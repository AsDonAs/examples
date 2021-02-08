<?php

namespace app\models;

use app\models\Record;
use yii\helpers\Json;
use app\modules\admin\models\ignoredTags\IgnoredTags;

class CompareWorker
{
    const TEST_FILE_TYPE = 'test';
    const SAMPLE_FILE_TYPE = 'sample';

    const DEFAULT_LINE = 'default';
    const CHANGED_LINE = 'changed';
    const ADDED_LINE = 'added';
    const DELETED_LINE = 'deleted';
    const NO_TYPE_LINE = 'notype';

    private $FIELDS_FOR_CHECK_SERVICES = [
        'DATE_IN',
        'CODE_USL',
    ];

    private $XML_CLASSES = [
        self::DEFAULT_LINE => '',
        self::CHANGED_LINE => 'changed',
        self::ADDED_LINE => 'added',
        self::DELETED_LINE => 'deleted',
    ];

    /**
     * @param $testZap
     * @param $sampleZap
     * @return array
     * @throws exceptions\NotFoundModelException
     */
    public function getXmlFiles($testZap, $sampleZap)
    {
        $lines = $this->getFullLines($testZap, $sampleZap);

        $testXml = $this->getXml(self::TEST_FILE_TYPE, $lines);
        $sampleXml = $this->getXml(self::SAMPLE_FILE_TYPE, $lines);

        return $files = [
            self::TEST_FILE_TYPE => $testXml,
            self::SAMPLE_FILE_TYPE => $sampleXml,
        ];
    }

    /**
     * @param $testZap
     * @param $sampleZap
     * @return array
     * @throws exceptions\NotFoundModelException
     */
    public function getAllLines($testZap, $sampleZap)
    {
        return $this->getFullLines($testZap, $sampleZap);
    }

    /**
     * @param $testZap
     * @param $sampleZap
     * @return array
     * @throws exceptions\NotFoundModelException
     */
    private function getFullLines($testZap, $sampleZap)
    {
        $testRecord = Record::getTestRecordByNumber($testZap);
        $sampleRecord = Record::getSampleRecordByNumber($sampleZap);

        $sampleRecord = $this->sortSampleRecordServices($testRecord, $sampleRecord);

        $niceTestArray = $testRecord->getNiceOneArrayData();
        $niceSampleArray = $sampleRecord->getNiceOneArrayData();

        $lines = $this->getLines($niceTestArray, $niceSampleArray);

        return $lines;
    }

    /**
     * @param $type
     * @param $lines
     * @return mixed
     */
    private function getXml($type, $lines)
    {
        $data = [];

        if ($type == self::TEST_FILE_TYPE) {
            $data = $this->getTestFromLines($lines);
        } elseif ($type == self::SAMPLE_FILE_TYPE) {
            $data = $this->getSampleFromLines($lines);
        }

        $hardArray = $this->convertToHardArray($data);

        return $this->convertToXml($hardArray);
    }

    /**
     * @param $lines
     * @return array
     */
    private function getTestFromLines($lines)
    {
        $resultArray = [];

        foreach($lines as $line) {
            $resultArray[$line['tag'] ] = [
                'value' => $line['testValue'],
                'type' => $line['type'],
            ];
        }

        return $resultArray;
    }

    /**
     * @param $lines
     * @return array
     */
    private function getSampleFromLines($lines)
    {
        $resultArray = [];

        foreach($lines as $line) {
            $resultArray[$line['tag']] = [
                'value' => $line['sampleValue'],
                'type' => $line['type'],
            ];
        }

        return $resultArray;
    }

    /**
     * @param $source
     * @return string
     */
    private function convertToXml($source)
    {
        $result = '';

        foreach ($source as $key => $settings) {
            $isSettings = isset($settings['value']) and isset($settings['type']);
            if (!$isSettings) {
                $result .= $this->addXmlTag($key, $settings);
            } else {
                $result .= $this->addXmlValue($key, $settings);
            }
        }

        return $result;
    }

    /**
     * @param $key
     * @param $settings
     * @return string
     */
    private function addXmlTag($key, $settings)
    {
        $result = '';

        $result .= "<div class='tag'><small>&lt;$key&gt;</small>";
        $result .= $this->convertToXml($settings);
        $result .= "<small>&lt;/$key&gt;</small></div>";

        return $result;
    }

    /**
     * @param $key
     * @param $settings
     * @return string
     */
    private function addXmlValue($key, $settings)
    {
        $result = '';

        $value = $settings['value'];
        $class = isset($this->XML_CLASSES[$settings['type']]) ? $this->XML_CLASSES[$settings['type']] : '';

        if ($value != '') {
            $result .= "<div class='tag $class'><small>&lt;$key&gt;</small>$value<small>&lt;/$key&gt;</small></div>";
        } else {
            $result .= "<div class='tag $class'><br></div>";
        }

        return $result;
    }

    /**
     * @param $testRecord
     * @param $sampleRecord
     * @return mixed
     */
    private function sortSampleRecordServices($testRecord, $sampleRecord)
    {
        $newSampleRecord = $sampleRecord;

        $testData = Json::decode($testRecord->raw_data);
        $sampleData = Json::decode($newSampleRecord->raw_data);

        $isTestServices = isset($testData['Z_SL']['SL']['USL']);
        $isSampleServices = isset($sampleData['Z_SL']['SL']['USL']);

        if ($isTestServices and $isSampleServices) {
            $sortedData = $this->sortServices($testData, $sampleData);
            $encodedData = Json::encode($sortedData);
            $newSampleRecord->raw_data = $encodedData;
        }

        return $newSampleRecord;
    }

    /**
     * @param $testDecodedData
     * @param $sampleDecodedData
     * @return array
     */
    private function sortServices($testDecodedData, $sampleDecodedData)
    {
        $testServices = $testDecodedData['Z_SL']['SL']['USL'];
        $sampleServices = $sampleDecodedData['Z_SL']['SL']['USL'];

        $isAloneTestService = isset($testDecodedData['Z_SL']['SL']['USL']['IDSERV']);
        $isAloneSampleService = isset($sampleDecodedData['Z_SL']['SL']['USL']['IDSERV']);

        if (!($isAloneTestService and $isAloneSampleService)) {
            $sortedServices = $this->getSortedServices($testServices, $sampleServices);
        } else {
            $sortedServices = $sampleServices;
        }

        $sampleDecodedData['Z_SL']['SL']['USL'] = $sortedServices;

        return $sampleDecodedData;
    }

    /**
     * @param $testServices
     * @param $sampleServices
     * @return array
     */
    private function getSortedServices($testServices, $sampleServices)
    {
        $sortedServices = $this->getOppositeTestServices($testServices, $sampleServices);

        $sortedServices = $this->addMissedServices($sortedServices, $sampleServices);

        return $sortedServices;
    }

    /**
     * @param $testServices
     * @param $sampleServices
     * @return array
     */
    private function getOppositeTestServices($testServices, $sampleServices)
    {
        $sortedServices = [];

        foreach ($testServices as $testService) {
            $isTestServiceHaveOpposite = false;

            foreach ($sampleServices as $sampleService) {
                if (!(in_array($sampleService, $sortedServices)) and ($isTestServiceHaveOpposite != true)) {
                    if ($this->isServiceEquivalent($testService, $sampleService)) {
                        $isTestServiceHaveOpposite = true;
                        $sortedServices[] = $sampleService;
                    }
                }
            }

            if (!$isTestServiceHaveOpposite) {
                $sortedServices[] = [];
            }
        }

        return $sortedServices;
    }

    /**
     * @param $sortedServices
     * @param $sampleServices
     * @return array
     */
    private function addMissedServices($sortedServices, $sampleServices)
    {
        $newSortedServices = $sortedServices;

        foreach ($sampleServices as $sampleService) {
            if (!(in_array($sampleService, $sortedServices))) {
                $newSortedServices[] = $sampleService;
            }
        }

        return $newSortedServices;
    }

    /**
     * @param $firstService
     * @param $secondService
     * @return bool
     */
    private function isServiceEquivalent($firstService, $secondService)
    {
        $checkFields = $this->FIELDS_FOR_CHECK_SERVICES;

        $equivalent = true;

        foreach ($checkFields as $checkField) {
            $isFieldsSet = (isset($firstService[$checkField]) and isset($secondService[$checkField]));
            if (!$isFieldsSet) {
                $equivalent = false;
            } else {
                if (!($firstService[$checkField] == $secondService[$checkField])) {
                    $equivalent = false;
                }
            }
        }

        return $equivalent;
    }

    /**
     * @param $testArray
     * @param $sampleArray
     * @return array
     */
    private function getLines($testArray, $sampleArray)
    {
        $lines = [];

        $lines = $this->addTest($lines, $testArray, $sampleArray);
        $lines = $this->addSample($lines, $testArray, $sampleArray);

        return $lines;
    }

    /**
     * @param $lines
     * @param $testArray
     * @param $sampleArray
     * @return array
     */
    private function addTest($lines, $testArray, $sampleArray)
    {
        $newLines = $lines;
        $index = 0;
        foreach ($testArray as $key => $value) {
            if (in_array($key, array_keys($sampleArray))) {
                if (($value == $sampleArray[$key]) or (IgnoredTags::isIgnoredTag($key))) {
                    $newLines = $this->extendLines($newLines, $index, self::DEFAULT_LINE, $key, $value, $sampleArray[$key]);
                } else {
                    $newLines = $this->extendLines($newLines, $index, self::CHANGED_LINE, $key, $value, $sampleArray[$key]);
                }
            } else {
                $newLines = $this->extendLines($newLines, $index, self::ADDED_LINE, $key, $value, '');
            }
            $index++;
        }

        return $newLines;
    }

    /**
     * @param $lines
     * @param $testArray
     * @param $sampleArray
     * @return array
     */
    private function addSample($lines, $testArray, $sampleArray)
    {
        $newLines = $lines;
        $index = 0;

        foreach ($sampleArray as $key => $value) {
            if (!in_array($key, array_keys($testArray))) {
                $correct = $this->getCorrect($index, $lines);
                $newIndex = $index + $correct;
                $newLines = $this->extendLines($newLines, $newIndex, self::DELETED_LINE, $key, '', $value);
            }
            $index++;
        }

        return $newLines;
    }

    /**
     * @param $index
     * @param $lines
     * @return int
     */
    private function getCorrect($index, $lines)
    {
        $correct = 0;
        $localIndex = 0;
        foreach ($lines as $line) {
            if ($line['type'] == self::ADDED_LINE) {
                if ($localIndex < $index) {
                    $correct += 1;
                }
            }
            $localIndex++;
        }

        return $correct;
    }

    /**
     * @param $lines
     * @param $index
     * @param $type
     * @param $tag
     * @param $testValue
     * @param $sampleValue
     * @return array
     */
    private function extendLines($lines, $index, $type, $tag, $testValue, $sampleValue)
    {
        $newLines = $lines;

        $temp = array_slice($newLines, 0, $index);
        $temp[] = [
            'type' => $type,
            'tag' => $tag,
            'testValue' => $testValue,
            'sampleValue' => $sampleValue,
        ];

        $newLines = array_merge($temp, array_slice($newLines, $index, count($newLines)));

        return $newLines;
    }

    /**
     * @param $array
     * @return array
     */
    private function convertToHardArray($array)
    {
        $resultArray = [];

        $reversedArray = array_reverse($array);

        foreach ($reversedArray as $elementKey => $elementSettings) {
            $elementValue = isset($elementSettings['value']) ? $elementSettings['value'] : '';
            $elementType = isset($elementSettings['type']) ? $elementSettings['type'] : '';
            $tagAsArray = explode('/', $elementKey);
            $resultArray = $this->insertInto($tagAsArray, $resultArray, $elementValue, $elementType);
        }

        return $resultArray;
    }

    /**
     * @param $places
     * @param $resultArray
     * @param $value
     * @param $type
     * @return mixed
     */
    private function insertInto($places, $resultArray, $value, $type)
    {
        $headPlace = $places[0];
        $end = count($places) - 1;
        $newPlaces = array_slice($places, 1, $end);

        if ($end > 0) {
            while (!isset($resultArray[$headPlace])) {
                $resultArray = $this->insertInArray($headPlace, [], $resultArray);
            }
            $resultArray[$headPlace] = $this->insertInto($newPlaces, $resultArray[$headPlace], $value, $type);
        } else {
            $resultArray = $this->insertInArray($headPlace, $value, $resultArray, $type);
        }

        return $resultArray;
    }

    /**
     * @param $elementKey
     * @param $elementValue
     * @param $type
     * @param $array
     * @return array
     */
    private function insertInArray($elementKey, $elementValue, $array, $type = self::NO_TYPE_LINE)
    {
        $newArray = $array;

        $temp = [];
        if ($type != self::NO_TYPE_LINE) {
            $temp[$elementKey] = [
                'value' => $elementValue,
                'type' => $type,
            ];
        } else {
            $temp[$elementKey] = $elementValue;
        }
        $newArray = array_merge($temp, $newArray);

        return $newArray;
    }
}
