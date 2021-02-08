<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\helpers\Json;
use app\models\exceptions\NotFoundModelException;

/**
 * This is the model class for table "record".
 *
 * @property int $id
 * @property int $is_sample
 * @property string $raw_data
 * @property string $created_at
 * @property integer $N_ZAP
 * @property string $NPOLIS
 * @property string $DATE_1
 * @property string $DS1
 */
class Record extends ActiveRecord
{
    const SAMPLE_FILE = 1;
    const TEST_FILE = 0;
    private $result;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'record';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['is_sample', 'raw_data'], 'required'],
            [['is_sample', 'N_ZAP'], 'integer'],
            [['raw_data'], 'string'],
            [['created_at'], 'safe'],
            [['NPOLIS', 'DATE_1', 'DS1'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'is_sample' => 'Is Sample',
            'raw_data' => 'Raw Data',
            'created_at' => 'Created At',
            'N_ZAP' => 'Number Zap',
            'NPOLIS' => 'Npolis',
            'DATE_1' => 'Date 1',
            'DS1' => 'Ds1',
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::className(),
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => ['created_at'],
                ],
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    /**
     * @return array
     */
    public function getNiceOneArrayData()
    {
        $data = Json::decode($this->raw_data);
        return $this->flatten_array($data);
    }

    /**
     * Преобразует многомерный массив в одномерный
     *
     * @param array $array
     * @return array
     */
    private function flatten_array(array $array)
    {
        array_walk($array, [$this, 'convert'], []);
        return $this->result;
    }

    /**
     * Конвертер массива
     *
     * @param $value
     * @param $key
     * @param $path
     */
    private function convert($value, $key, $path)
    {
        if (is_array($value)) {
            $path[] = $key;
            array_walk($value, [$this, 'convert'], $path);
        } else {
            $prefix = count($path) ? implode($path, '/') . '/' : '';
            $this->result[$prefix . $key] = $value;
        }
    }

    /**
     * @param $number
     * @return array|null|ActiveRecord
     * @throws NotFoundModelException
     */
    public static function getTestRecordByNumber($number)
    {
        return self::getRecordByNumber(self::TEST_FILE, $number);
    }

    /**
     * @param $number
     * @return array|null|ActiveRecord
     * @throws NotFoundModelException
     */
    public static function getSampleRecordByNumber($number)
    {
        return self::getRecordByNumber(self::SAMPLE_FILE, $number);
    }

    /**
     * @param $type
     * @param $number
     * @return array|null|ActiveRecord
     * @throws NotFoundModelException
     */
    private static function getRecordByNumber($type, $number)
    {
        $record = self::find()
            ->where(['is_sample' => $type])
            ->andWhere(['N_ZAP' => $number])
            ->limit(1)
            ->one();

        if ($record) {
            return $record;
        }

        throw new NotFoundModelException('Не нашлось записи для полученного номера.');
    }
}
