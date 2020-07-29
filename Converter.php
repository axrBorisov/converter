<?php
declare(strict_types=1);

class Converter
{
    private string $directory = 'out';
    private array $data;

    public function __construct()
    {
        $this->data = [
            'categories' => [],
            'items' => [],
            'rests' => [],
        ];
    }

    public function unzipFiles(): void
    {
        $zip = new ZipArchive();
        $zipFiles = scandir($this->directory);

        if (!$zipFiles) die('The wrong directory');

        foreach ($zipFiles as $key => $file) {
            $fileInfo = pathinfo($this->directory . '/' . $file);
            if (isset($fileInfo['extension']) && $fileInfo['extension'] === 'zip') {
                $openFile = $zip->open($this->directory . '/' . $file);
                if ($openFile === true) {
                    $zip->extractTo($this->directory);
                    $zip->close();

                    // deleting unzipped files
                    if (file_exists($this->directory . '/' . $file)) {
                        unlink($this->directory . '/' . $file);
                    }
                } else {
                    error_log('error: ' . $openFile . PHP_EOL, 3, 'errors.log');
                }
            }
        }
    }

    private function getXmlFiles(): array
    {
        $xmlFiles = [];
        $files = scandir($this->directory);

        foreach ($files as $key => $file) {
            $fileInfo = pathinfo($this->directory . '/' . $file);
            if (isset($fileInfo['extension']) && $fileInfo['extension'] === 'xml') {
                $xmlFiles[] = $file;
            }
        }

        return $xmlFiles;
    }

    private function getDataFromXml(): void
    {
        $xmlFiles = $this->getXmlFiles();
        foreach ($xmlFiles as $file) {
            $xmlFile = simplexml_load_file($this->directory . '/' . $file);
            if (!$xmlFile) die('XML файл не найден');
            $xml = new SimpleXMLElement($xmlFile->asXML());

            // get categories
            if (isset($xml->{'Классификатор'}->{'Группы'}->{'Группа'})) {
                foreach ($xml->{'Классификатор'}->{'Группы'}->{'Группа'} as $key => $value) {
                    $this->data['categories'][] = [
                        'id' => (string)$value->{'Ид'},
                        'name' => (string)$value->{'Наименование'},
                    ];
                }
            }

            // get items
            if (isset($xml->{'Каталог'}->{'Товары'}->{'Товар'})) {
                foreach ($xml->{'Каталог'}->{'Товары'}->{'Товар'} as $key => $item) {
                    $barcode = (string)$item->{'Штрихкод'};
                    $item_id = (string)$item->{'Ид'};

                    $this->data['items'][$item_id]['id'] = $item_id;
                    $this->data['items'][$item_id]['barcode'] = $barcode;
                    $this->data['items'][$item_id]['category_id'] = (string)$item->{'Группы'}->{'Ид'};
                    $this->data['items'][$item_id]['description'] = (string)$item->{'Описание'};
                    $this->data['items'][$item_id]['name'] = (string)$item->{'Наименование'};
                    $this->data['items'][$item_id]['unit_id'] = (int)$item->{'БазоваяЕдиница'};

                    if (strpos($barcode, ',') !== false) {
                        $barcodes = explode(',', $barcode);
                        foreach ($barcodes as $separatedBarcode) {
                            $this->checkBarcode(trim($separatedBarcode));
                        }
                    } else {
                        $this->checkBarcode($barcode);
                    }
                }
            }

            // get prices for items
            if (isset($xml->{'ПакетПредложений'}->{'Предложения'}->{'Предложение'}->{'Цены'})) {
                foreach ($xml->{'ПакетПредложений'}->{'Предложения'}->{'Предложение'} as $key => $item) {
                    $price = (float)$item->{'Цены'}->{'Цена'}->{'ЦенаЗаЕдиницу'};
                    $item_id = (string)$item->{'Ид'};
                    if ($price > 0) {
                        foreach ($this->data as $dataKey => $dataValue) {
                            $this->data['items'][$item_id]['id'] = $item_id;
                            $this->data['items'][$item_id]['price'] = $price;
                        }
                    }
                }
            }

            // get rests
            if (isset($xml->{'ПакетПредложений'}->{'Предложения'}->{'Предложение'}->{'Остатки'})) {
                $warehouses = $xml->{'ПакетПредложений'}->{'Предложения'}->{'Предложение'};
                foreach ($warehouses as $key => $warehouse) {
                    $warehouse_id = (string)$warehouse->{'Ид'};
                    foreach ($warehouse->{'Остатки'}->{'Остаток'} as $item) {
                        $this->data['rests'][$warehouse_id]['warehouse_id'] = $warehouse_id;
                        $this->data['rests'][$warehouse_id][] = [
                            'item_id' => (string)$item->{'Склад'}->{'Ид'},
                            'amount' => (int)$item->{'Склад'}->{'Количество'},
                        ];
                    }
                }
            }
        }

        // remove keys from items
        sort($this->data['items']);
        // remove keys from rests
        sort($this->data['rests']);
    }

    public function convertXmlDataToJsonFile(): void
    {
        $this->getDataFromXml();

        $file = fopen('results.json', 'w');
        fwrite($file, json_encode($this->data, JSON_UNESCAPED_UNICODE));
        fclose($file);
    }

    private function checkBarcode(string $barcode): bool
    {
        $sumEvenIndexes = 0;
        $sumOddIndexes = 0;

        $splittedBarcode = array_map('intval', str_split($barcode));

        if (count($splittedBarcode) !== 13) {
            error_log("Invalid barcode: $barcode" . PHP_EOL, 3, 'errors.log');
            return false;
        };

        for ($i = 0; $i < count($splittedBarcode) - 1; $i++) {
            if ($i % 2 === 0) {
                $sumOddIndexes += $splittedBarcode[$i];
            } else {
                $sumEvenIndexes += $splittedBarcode[$i];
            }
        }

        $rest = ($sumOddIndexes + (3 * $sumEvenIndexes)) % 10;

        if ($rest !== 0) {
            $rest = 10 - $rest;
        }

        if ($rest === $splittedBarcode[12]) {
            return true;
        } else {
            error_log("Invalid barcode: $barcode" . PHP_EOL, 3, "errors.log");
            return false;
        }
    }
}

$converter = new Converter();;
$converter->unzipFiles();
$converter->convertXmlDataToJsonFile();