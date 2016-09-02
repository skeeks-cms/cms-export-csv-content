<?php
return [

    'components' =>
    [
        'cmsExport' => [
            'handlers'     =>
            [
                'skeeks\cms\exportCsvContent\ExportCsvContentHandler' =>
                [
                    'class' => 'skeeks\cms\exportCsvContent\ExportCsvContentHandler'
                ]
            ]
        ],

        'i18n' => [
            'translations' =>
            [
                'skeeks/exportCsvContent' => [
                    'class'             => 'yii\i18n\PhpMessageSource',
                    'basePath'          => '@skeeks/cms/exportCsvContent/messages',
                    'fileMap' => [
                        'skeeks/exportCsvContent' => 'main.php',
                    ],
                ]
            ]
        ]
    ]
];