<?php

namespace App\Services;

use App\Models\TechnicalVoice;
use GuzzleHttp\Client;
use Storage;

/**
 * Class YandexVoiceService
 * @package App\Services
 */
class YandexVoiceService
{
    CONST MAX_CORRECT_TEXT_SIZE = 15000;
    CONST MAX_SPLIT_SIZE_LENGTH = 4999;
    CONST SPLIT_DELIMITERS = [".", "?", "!"];
    CONST STORAGE_DISK = "public";
    CONST USAGE_FILE_NAMES = ["mainPart", "partFile", "tempFile"];
    CONST YANDEX_VOICE_URL = "https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize";
    CONST YANDEX_VOICE = "filipp";
    CONST YANDEX_SPEED = "0.9";

    /** @var \Illuminate\Filesystem\FilesystemAdapter $fileStorage */
    private $fileStorage;

    /**
     * YandexVoiceService constructor.
     */
    public function __construct()
    {
        $this->fileStorage = Storage::disk(self::STORAGE_DISK);
    }

    /**
     * @param string $text
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function getVoiceFileAsText($text)
    {
        $textParts = $this->splitForRequests($text);

        $voices = [];

        foreach ($textParts as $textPart) {
            $voice = $this->getVoice($textPart);
            $voices[] = $voice;
        }

        $fullVoice = $this->concatenateParts($voices);

        return $fullVoice;
    }

    /**
     * @param string $text
     * @return bool
     */
    public function isCorrectText($text)
    {
        $correct = true;

        $lengthText = mb_strlen($text);

        if ($lengthText > self::MAX_CORRECT_TEXT_SIZE) {
            $correct = false;
        }

        return $correct;
    }

    /**
     * @param string $text
     * @return array
     */
    private function splitForRequests($text)
    {
        $parts = [];

        $remainder = $text;
        $length = mb_strlen($remainder);

        $delimiters = self::SPLIT_DELIMITERS;
        $maxPartLength = self::MAX_SPLIT_SIZE_LENGTH;

        while ($length > $maxPartLength) {
            $mainPart = mb_substr($remainder, 0, $maxPartLength);
            $mainPartLength = mb_strlen($mainPart);

            $maxPart = "";
            $maxLength = 0;

            foreach ($delimiters as $delimiter) {
                $reversePart = mb_strrchr($mainPart, $delimiter);

                if ($reversePart !== false) {
                    $reverseLength = mb_strlen($reversePart);
                    $neededPartLength = $mainPartLength - $reverseLength + 1;
                    $part = mb_substr($mainPart, 0, $neededPartLength);

                    $lengthPart = mb_strlen($part);

                    if ($lengthPart > $maxLength) {
                        $maxPart = $part;
                        $maxLength = $lengthPart;
                    }
                }
            }


            if ($maxPart == "") {
                $maxPart = $mainPart;
                $maxLength = mb_strlen($maxPart);
            }

            $parts[] = trim($maxPart);

            $start = $maxLength;
            $remainder = mb_substr($remainder, $start);
            $length = mb_strlen($remainder);
        }

        if ($length > 0) {
            $parts[] = trim($remainder);
        }

        return $parts;
    }

    /**
     * @param string $text
     * @return string
     */
    private function getVoice($text)
    {
        $client = new Client();
        $url = self::YANDEX_VOICE_URL;
        $apiToken = env("YANDEX_VOICE_API_TOKEN", null);
        $folderId = env("YANDEX_VOICE_FOLDER_ID", null);
        $yandexVoice = self::YANDEX_VOICE;
        $headers = ["Authorization" => "Api-Key " . $apiToken];


        $response = $client->post($url, [
            "multipart" => [
                [
                    "name" => "lang",
                    "contents" => "ru-RU",
                ],
                [
                    "name" => "folderId",
                    "contents" => $folderId,
                ],
                [
                    "name" => "text",
                    "contents" => $text,
                ],
                [
                    "name" => "voice",
                    "contents" => $yandexVoice,
                ],
            ],
            "headers" => $headers,
        ]);

        $voice = $response->getBody();

        return $voice;
    }

    /**
     * @param array $parts
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function concatenateParts($parts)
    {
        if (empty($parts)) {
            return "";
        }

        $format = ".ogg";

        $mainPartName = "mainPart";

        foreach (self::USAGE_FILE_NAMES as $fileName) {
            $fullFileName = $fileName . $format;

            $exists = $this->fileStorage->exists($fullFileName);

            if ($exists) {
                $this->fileStorage->delete($fullFileName);
            }

            $convertedFullFileName = $fileName . "Converted" . $format;

            $exists = $this->fileStorage->exists($convertedFullFileName);

            if ($exists) {
                $this->fileStorage->delete($convertedFullFileName);
            }
        }

        $firstPart = $parts[0];
        unset($parts[0]);

        $this->createMainPart($mainPartName, $format, $firstPart);

        foreach ($parts as $part) {
            $this->updateMainPart($mainPartName, $format, $part);
        }

        $fullMainPartName = $mainPartName . $format;

        $result = $this->fileStorage->get($fullMainPartName);

        $exists = $this->fileStorage->exists($fullMainPartName);

        if ($exists) {
            $this->fileStorage->delete($fullMainPartName);
        }

        return $result;
    }

    /**
     * @param $mainPartName
     * @param $format
     * @param $content
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function createMainPart($mainPartName, $format, $content)
    {
        $fullName = $mainPartName . $format;
        $this->fileStorage->put($fullName, $content);

        $convertedName = $mainPartName . "Converted.wav";
        $convertedLastName = $mainPartName . "Converted" . $format;

        $fullPath = $this->fileStorage->path($fullName);
        $fullPathConverted = $this->fileStorage->path($convertedName);
        $fullPathConvertedLast = $this->fileStorage->path($convertedLastName);

        $this->convertFromOpus($fullPath, $fullPathConverted);

        $convertCommand = "sox " . $fullPathConverted . " " . $fullPathConvertedLast;
        exec($convertCommand);

        $this->fileStorage->delete($convertedName);
        $this->fileStorage->delete($fullName);
        $this->fileStorage->rename($convertedLastName, $fullName);
    }

    /**
     * @param $mainPartName
     * @param $format
     * @param $content
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function updateMainPart($mainPartName, $format, $content)
    {
        $partFileName = "partFile";
        $tempFileName = "tempFile";

        $fullMainName = $mainPartName . $format;
        $fullPartFileName = $partFileName . $format;
        $fullTempFileName = $tempFileName . $format;

        $this->fileStorage->put($fullPartFileName, $content);

        $convertedName = $partFileName . "Converted.wav";

        $fullPathMain = $this->fileStorage->path($fullMainName);
        $fullPathPart = $this->fileStorage->path($fullPartFileName);
        $fullPathConverted = $this->fileStorage->path($convertedName);
        $fullPathTemp = $this->fileStorage->path($fullTempFileName);

        $this->convertFromOpus($fullPathPart, $fullPathConverted);

        $concatenateCommand = "sox --combine concatenate " . $fullPathMain . " " . $fullPathConverted . " " . $fullPathTemp;
        exec($concatenateCommand);

        $this->fileStorage->delete($convertedName);
        $this->fileStorage->delete($fullPartFileName);
        $this->fileStorage->delete($fullMainName);
        $this->fileStorage->rename($fullTempFileName, $fullMainName);
    }

    /**
     * @param $fileName
     * @param $convertedName
     */
    private function convertFromOpus($fileName, $convertedName)
    {
        $command = "opusdec --force-wav " . $fileName . " " . $convertedName;

        exec($command);
    }
}
