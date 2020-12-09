<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 29.08.2016
 */
namespace skeeks\cms\exportCsvContent;

use skeeks\cms\export\ExportHandler;
use skeeks\cms\export\ExportHandlerFilePath;
use skeeks\cms\importCsv\handlers\CsvHandler;
use skeeks\cms\importCsv\helpers\CsvImportRowResult;
use skeeks\cms\importCsv\ImportCsvHandler;
use skeeks\cms\importCsvContent\widgets\MatchingInput;
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\relatedProperties\PropertyType;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeElement;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\widgets\ActiveForm;

/**
 * @property CmsContent $cmsContent
 *
 * Class CsvContentHandler
 *
 * @package skeeks\cms\importCsvContent
 */
class ExportCsvContentHandler extends ExportHandler
{
    public $content_id = null;

    public $file_path = '';


    const CSV_CHARSET_UTF8           = 'UTF-8';             //другой
    const CSV_CHARSET_WINDOWS1251    = 'windows-1251';             //другой

    /**
     * @var string
     */
    public $charset = self::CSV_CHARSET_UTF8;


    /**
     * Доступные кодировки
     * @return array
     */
    static public function getCsvCharsets()
    {
        return [
            self::CSV_CHARSET_UTF8              => self::CSV_CHARSET_UTF8,
            self::CSV_CHARSET_WINDOWS1251       => self::CSV_CHARSET_WINDOWS1251,
        ];
    }


    public function init()
    {
        $this->name = \Yii::t('skeeks/exportCsvContent', '[CSV] Export content items');

        if (!$this->file_path)
        {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d") . "-" . \Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/content/content-{$rand}.csv";
        }

        parent::init();
    }

    public function getAvailableFields()
    {
        $element = new CmsContentElement([
            'content_id' => $this->cmsContent->id
        ]);

        $fields = [];

        foreach ($element->attributeLabels() as $key => $name)
        {
            $fields['element.' . $key] = $name;
        }

        foreach ($element->relatedPropertiesModel->attributeLabels() as $key => $name)
        {
            $fields['property.' . $key] = $name . " [свойство]";
        }

        return array_merge(['' => ' - '], $fields);
    }

    /**
     * @return null|CmsContent
     */
    public function getCmsContent()
    {
        if (!$this->content_id)
        {
            return null;
        }

        return CmsContent::findOne($this->content_id);
    }



    /**
     * Соответствие полей
     * @var array
     */
    public $matching = [];

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [

            ['content_id' , 'required'],
            ['content_id' , 'integer'],

            ['charset' , 'string'],

            [['matching'], 'safe'],
            [['matching'], function($attribute) {
                if (!in_array('element.name', $this->$attribute))
                {
                    $this->addError($attribute, "Укажите соответствие названия");
                }
            }]
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'content_id'        => \Yii::t('skeeks/importCsvContent', 'Контент'),
            'matching'          => \Yii::t('skeeks/importCsvContent', 'Preview content and configuration compliance'),
            'charset'          => \Yii::t('skeeks/importCsvContent', 'Кодировка'),
        ]);
    }

    /**
     * @param ActiveForm $form
     */
    public function renderConfigForm(ActiveForm $form)
    {
        parent::renderConfigForm($form);

        echo $form->field($this, 'charset')->listBox(
            $this->getCsvCharsets(), [
                'size' => 1,
                'data-form-reload' => 'true'
            ]);

        echo $form->field($this, 'content_id')->listBox(
            array_merge(['' => ' - '], CmsContent::getDataForSelect()), [
            'size' => 1,
            'data-form-reload' => 'true'
        ]);
    }



    public function export()
    {
        ini_set("memory_limit","8192M");
        set_time_limit(0);

        //Создание дирректории
        if ($dirName = dirname($this->rootFilePath))
        {
            $this->result->stdout("Создание дирректории\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName))
            {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }

        $this->charset = $this->charset . "//IGNORE";


        $elements = CmsContentElement::find()->where([
            'content_id' => $this->content_id
        ]);

        $countTotal = $elements->count();
        $this->result->stdout("\tЭлементов найдено: {$countTotal}\n");



        $element = $elements->one();

        $fp = fopen($this->rootFilePath, 'w');

        $head = [];
        foreach ($element->toArray() as $code => $value)
        {
            $head[] = "element." . $code;
        }

        $head[] = "element.mainImageSrc";

        /**
         * @var $element CmsContentElement
         */
        foreach ($element->relatedPropertiesModel->toArray() as $code => $value)
        {
            $head[] = 'property.' . $code;
        }

        fputcsv($fp, $head, ";");


        $head = [];

        foreach ($element->toArray() as $code => $value)
        {
            if ($val = iconv(\Yii::$app->charset, $this->charset, $element->getAttributeLabel($code))) {
                $head[] = $val;
            } else {
                $head[] = $element->getAttributeLabel($code);
            }
        }

        if ($val = iconv(\Yii::$app->charset, $this->charset, "Ссылка на главное изображение")) {
            $head[] = $val;
        } else {
            $head[] = "Ссылка на главное изображение";
        }

        /**
         * @var $element CmsContentElement
         */
        foreach ($element->relatedPropertiesModel->toArray() as $code => $value)
        {
            $property = $element->relatedPropertiesModel->getRelatedProperty($code);

            if ($val = iconv(\Yii::$app->charset, $this->charset, trim($property->name))) {
                $head[] = $val;
            } else {
                $head[] = $property->name;
            }

        }

        fputcsv($fp, $head, ";");

        foreach ($elements->each(10) as $element)
        {
            $propertiesRow = [];
            $propertiesRow[] = $element->image ? $element->image->absoluteSrc : "";

            foreach ($element->relatedPropertiesModel->toArray() as $code => $value)
            {
                $value = $element->relatedPropertiesModel->getSmartAttribute($code);
                if (is_array($value))
                {
                    $value = implode(', ', $value);
                }

                $propertiesRow[$code] = $value;
            }



            $row = array_merge($element->toArray(), $propertiesRow);

            if (\Yii::$app->charset != $this->charset)
            {
                foreach ($row as $key => $value)
                {
                    if (is_string($value))
                    {
                        if ($val = iconv(\Yii::$app->charset, $this->charset, $value)) {
                            $row[$key] = $val;
                        } else {
                            $row[$key] = $value;
                        }

                    }
                }
            }

            fputcsv($fp, $row, ";");
        }

        fclose($fp);

        return $this->result;
    }
}