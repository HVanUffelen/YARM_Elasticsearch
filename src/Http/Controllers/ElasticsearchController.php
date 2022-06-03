<?php

namespace Yarm\Elasticsearch;

use App\Http\Controllers\ExportController;
use App\Models\File;
use App\Models\Ref;
use App\Models\Style;
use function App\Http\Controllers\storage_path;
use function App\Http\Controllers\view;
use function dd;

class ElasticsearchController extends Elasticsearch
{

    /**
     * @param $index
     * @param $type
     * @param $query
     * @param $language
     * @return array
     */
    static function ESsearch($index, $type, $query, $request)
    {
        try {
            $es = new Elasticsearch;
            $result = $es->search($index, $type, $query, $request);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return $result;
    }

    public static function deleteFileFromElasticSearch($file)
    {
        //delete files from Elasticsearch
        $index = 'refs';
        $type = 'ref';
        $id = $file->id;
        $Es_model = new Elasticsearch();
        $Es_model->deleteId($index, $type, $id);
    }

    public static function storeFilesToElasticSearch($file)
    {
        $arrayExtensions=['pdf','txt','html','xml','doc','docx','odt'];
        try {
            $es = new Elasticsearch();
        } catch (\Throwable $e) {
            self::sendMailOnErrorES('FileController - storeFilesToElasticSearch Constructor - ', 'Error saving File to Elasticsearch!', $e, '0');
            return back()->with('alert-danger', 'Error 2a saving file to Elasticsearch. File id =  0 (Constructor)' . '(' . $e->getMessage() . ') Please contact the administrator');
        }

        $index_name = 'refs';
        $fileName = $file['name'];
        $id = $file['id'];
        $refId = $file['ref_id'];

        $ref = Ref::find($refId);
        $data['ref'] = $ref;
        $dataSet = $ref->prepareDataset();

        $author = $dataSet['author'];
        $title = $dataSet['title'];
        $year = $dataSet['year'];
        $keywords = $dataSet['keywords'];
        $primary = $dataSet['primarytxt'];

        if (isset($dataSet['language_source']))
            $languageSource = $dataSet['language_source'];
        else
            $languageSource = 'Unknown';
        if (isset($dataSet['language_target']))
            $languageTarget = $dataSet['language_target'];
        else
            $languageTarget = 'Unknown';

        $type = $dataSet['type'];

        if ($keywords != '')
            $pos = strpos("secondary", $keywords);
        else
            $pos = false;

        if ($primary == '1' or $pos === false)
            $primary = 'Yes';
        else
            $primary = 'No';

        $fileAndPath = storage_path() . '/app/DLBTUploads/' . $file->name;

        $citation = ExportController::reformatBladeExport(view('dlbt.styles.format_as_' . Style::getNameStyle(), $data)->render());
        if (in_array(pathinfo($fileAndPath, PATHINFO_EXTENSION),$arrayExtensions)) {
            try {
                $res = $es->createUpdateFile($index_name, $fileAndPath, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget, $type);
                $fileToSave = File::find($id);
                if ($res === true) {
                    $fileToSave->esearch = 'yes';
                } else {
                    $fileToSave->esearch = $res;
                }
                $fileToSave->save();
            } catch (\Throwable $e) {
                $fileToSave = File::find($id);
                $fileToSave->esearch = 'error';
                $fileToSave->save();
                self::sendMailOnErrorES('FileController - storeFilesToElasticSearch - ', 'Error saving File to Elasticsearch!', $e, $id);
                return back()->with('alert-danger', 'Error 2b saving file to Elasticsearch. File id =  ' . $id . '(' . $e->getMessage() . ') Please contact the administrator');
            }
        } else {
            $fileToSave = File::find($id);
            $fileToSave->esearch = 'no - wrong extension';
            $fileToSave->save();
        }

    }

    /**
     *
     */
    static function upload2ElasticSearch($retryNotFound = false, $specificId = null)  //only use for Bulk-Upload
    {

        $es = new Elasticsearch();
        if ($retryNotFound) {
            $files = File::where('esearch', 'like', '%not f%')->get();
        } elseif (isset($specificId)) {
            $files = File::find($specificId)->get();
        } else {
            $files = File::getFileData4ES();
        }

        //$i=1;
        foreach ($files as $file) {

            $index_name = 'refs';
            $fileName = $file['name'];
            $id = $file['fileId'];
            $refId = $file['refId'];

            $ref = Ref::find($refId);
            $data['ref'] = $ref;
            $dataSet = $ref->prepareDataset();

            $author = $dataSet['author'];
            $title = $dataSet['title'];
            $year = $dataSet['year'];
            $keywords = $dataSet['keywords'];
            $primary = $dataSet['primarytxt'];

            if (isset($dataSet['language_source']))
                $languageSource = $dataSet['language_source'];
            else
                $languageSource = 'Unknown';
            if (isset($dataSet['language_target']))
                $languageTarget = $dataSet['language_target'];
            else
                $languageTarget = 'Unknown';
            $type = $dataSet['type'];

            if ($keywords != '')
                $pos = strpos("secondary", $keywords);
            else
                $pos = false;

            if ($primary == '1' or $pos === false)
                $primary = 'Yes';
            else
                $primary = 'No';

            $fileInfo = storage_path() . '/app/DLBTUploads/' . $file['name'];

            $citation = ExportController::reformatBladeExport(view('dlbt.styles.format_as_' . Style::getNameStyle(), $data)->render());

            try {
                $res = $es->createUpdateFile($index_name, $fileInfo, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget, $type);
            } catch (\Throwable $e) {
                $fileToSave = File::find($id);
                $fileToSave->esearch = 'general error';
                $fileToSave->save();
                //echo('<p style=\"color:red;\"> Error with fileId =' . $id . ' - RefId = ' . $refId . '</p>');
                //continue;
            }

        }
    }

    static function updateElasticSearch()  //only use for Bulk-Upload
    {
        $max = 10000;
        $es = new Elasticsearch();

        $index = 'refs';
        for ($i = 1; $i <= $max; $i++) {
            $ref = Ref::find($i);
            if (isset($ref)) {
                try {
                    $presentInES = $es->searchRefID($index, $ref);
                    if (isset($presentInES) && count($presentInES['hits']['hits']) >= 1) {
                        $success = $es->updateESforRefID($presentInES, $index, $ref);
                    } else {
                        $success = $es->storeNewESforRefId($index, $ref);
                    }
                } catch (\Throwable $e) {
                    dd($e);
                }
            }
        }
        dd($searchResult = $es->searchInAllFields($index,'Aafjes'));
    }

    /*public function upLoadFilesToES (){

        $es = new Elasticsearch();
        if ($retryNotFound) {
            $files = File::where('esearch', 'like', '%not f%')->get();
        } elseif (isset($specificId)) {
            $files = File::find($specificId)->get();
        } else {
            $files = File::getFileData4ES();
        }

        //$i=1;
        foreach ($files as $file) {

            $index_name = 'refs';
            $fileName = $file['name'];
            $id = $file['fileId'];
            $refId = $file['refId'];

            $ref = Ref::find($refId);
            $data['ref'] = $ref;
            $dataSet = $ref->prepareDataset();

            $author = $dataSet['author'];
            $title = $dataSet['title'];
            $year = $dataSet['year'];
            $keywords = $dataSet['keywords'];
            $primary = $dataSet['primarytxt'];

            if (isset($dataSet['language_source']))
                $languageSource = $dataSet['language_source'];
            else
                $languageSource = 'Unknown';
            if (isset($dataSet['language_target']))
                $languageTarget = $dataSet['language_target'];
            else
                $languageTarget = 'Unknown';
            $type = $dataSet['type'];

            if ($keywords != '')
                $pos = strpos("secondary", $keywords);
            else
                $pos = false;

            if ($primary == '1' or $pos === false)
                $primary = 'Yes';
            else
                $primary = 'No';

            $fileInfo = storage_path() . '/app/DLBTUploads/' . $file['name'];

            $citation = ExportController::reformatBladeExport(view('dlbt.styles.format_as_' . Style::getNameStyle(), $data)->render());

            try {
                $res = $es->createUpdateFile($index_name, $fileInfo, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget,$type);
            } catch (\Throwable $e) {
                $fileToSave = File::find($id);
                $fileToSave->esearch = 'general error';
                $fileToSave->save();
                //echo('<p style=\"color:red;\"> Error with fileId =' . $id . ' - RefId = ' . $refId . '</p>');
                //continue;
            }

        }*/
}
