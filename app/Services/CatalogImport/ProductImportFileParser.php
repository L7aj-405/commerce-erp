<?php

namespace App\Services\CatalogImport;

use DateInterval;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;
use ZipArchive;

class ProductImportFileParser
{
    /** @return array{format:string, headers:list<string>, rows:list<list<string>>, original_name:string} */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! $path || ! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Le fichier doit être au format CSV ou XLSX.']);
        }

        $reader = $extension === 'xlsx' ? $this->xlsx($path) : $this->csv($path);

        try {
            $reader->open($path);
            [$headers, $rows] = $this->read($reader);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne peut pas être lu. Vérifiez son format et réessayez.']);
        } finally {
            try {
                $reader->close();
            } catch (Throwable) {
                // The reader may not have opened successfully.
            }
        }

        if ($headers === [] || $rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier doit contenir une ligne d’en-tête et au moins un produit.']);
        }

        return [
            'format' => $extension,
            'headers' => $headers,
            'rows' => $rows,
            'original_name' => $this->safeName($file->getClientOriginalName()),
        ];
    }

    private function csv(string $path): CsvReader
    {
        $contents = file_get_contents($path);
        if ($contents === false || str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'Le CSV doit être encodé en UTF-8.']);
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $commaCount = count(str_getcsv($firstLine, ',', '"', ''));
        $semicolonCount = count(str_getcsv($firstLine, ';', '"', ''));
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $semicolonCount > $commaCount ? ';' : ',';

        return new CsvReader($options);
    }

    private function xlsx(string $path): XlsxReader
    {
        $mime = mime_content_type($path);
        $allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'];
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'Le contenu du fichier XLSX n’est pas valide.']);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Le contenu du fichier XLSX n’est pas valide.']);
        }
        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'Le contenu du fichier XLSX n’est pas valide.']);
        }
        $zip->close();

        return new XlsxReader;
    }

    /** @return array{list<string>, list<list<string>>} */
    private function read(ReaderInterface $reader): array
    {
        $headers = [];
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($cell) => $this->value($cell), $row->getCells());
                if (count(array_filter($values, fn ($value) => $value !== '')) === 0) {
                    continue;
                }
                if ($headers === []) {
                    if (count($values) > (int) config('catalog_imports.max_columns')) {
                        throw ValidationException::withMessages(['file' => 'Le fichier contient trop de colonnes.']);
                    }
                    $headers = array_map(fn ($value, $index) => trim($value) !== '' ? trim($value) : 'Colonne '.($index + 1), $values, array_keys($values));
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

                    continue;
                }
                if (count($rows) >= (int) config('catalog_imports.max_rows')) {
                    throw ValidationException::withMessages(['file' => 'Le fichier dépasse la limite de '.config('catalog_imports.max_rows').' lignes.']);
                }
                $rows[] = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            }
            break;
        }

        return [$headers, $rows];
    }

    private function value($cell): string
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof DateInterval) {
            return $value->format('%r%a days');
        }
        if (is_float($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        return trim((string) $value);
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '-', $name) ?: 'catalogue';

        return mb_substr($name, 0, 255);
    }
}
