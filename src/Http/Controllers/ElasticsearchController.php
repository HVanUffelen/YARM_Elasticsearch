<?php

namespace Yarm\Elasticsearch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\Ref;
use App\Models\Style;
use Yarm\Elasticsearch\Models\Elasticsearch;
use Illuminate\Support\Facades\Storage;

class ElasticsearchController extends Controller
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
        $arrayExtensions = ['pdf', 'txt', 'html', 'xml', 'doc', 'docx', 'odt'];
        try {
            $es = new Elasticsearch();
        } catch (\Throwable $e) {
            return ['FileController - storeFilesToElasticSearch Constructor - ','Error saving File to Elasticsearch!',$e,$file['id']];
        }

        $index_name = 'refs';
        $fileName = $file['name'];
        $id = $file['id'];
        $refId = $file['ref_id'];

        $ref = Ref::find($refId);

        $data['ref'] = $ref;

        list($allFields,$dataSet) = $es->createAllfields($ref);
        //$dataSet = $ref->prepareDataset();

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

        $fileAndPath = storage_path() . '/app/YARMDBUploads/' . $file->name;
        try {
            $citation = ExportController::reformatBladeExport(view('ydbviews.styles.format_as_' . strtolower(Style::getNameStyle()), $data)->render());
            return ['FileController - storeFilesToElasticSearch Render Citation - ','Error saving File to Elasticsearch!','Test', json_encode($data)];
        } catch (\Throwable $e) {
            return ['FileController - storeFilesToElasticSearch Render Citation - ','Error saving File to Elasticsearch!',$e, json_encode($data)];
        }



        if (in_array(pathinfo($fileAndPath, PATHINFO_EXTENSION), $arrayExtensions)) {
            try {
                $res = $es->createUpdateFile($index_name, $fileAndPath, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget, $type, $allFields);
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
                return ['FileController - storeFilesToElasticSearch (Save File) ','Error saving File to Elasticsearch!',$e,$dataSet['id']];
            }
        } else {
            $fileToSave = File::find($id);
            $fileToSave->esearch = 'no - wrong extension';
            $fileToSave->save();
            return Null;
        }

    }

    public
    static function searchInElasticSearch($request)
    {
        $i = 0;
        $query = false;

        foreach ($request['field0'] as $field) {
            //don't look for excluded terms
            if (strtoupper($request['criterium0'][$i]) == 'LIKE' or $request['criterium0'][$i] == '=') {
                $query = true;
            }
            $i++;
        }

        if ($query == true) {
            //toDo change Elasticsearch db - import Sek and Prim and select on Primary!
            if (config('elasticsearch.elasticsearch_present') == true) {
                if ($request['search_in'] == 'all_fields') {
                    $pos = array_search('all_fields', $request['field0']);
                    $string = $request['search0'][$pos];
                    $es = new Elasticsearch();
                    $resultES = $es->searchInAllFields('refs', $string);
                } else {
                    $resultES = ElasticsearchController::ESsearch('refs', 'ref', $query, $request);
                }
                //Todo Lang - change Error - managment (use Array in $resultES, Check on ['hits']
                if ($resultES == 'No alive nodes found in your cluster' || isset($resultES['error'])) {
                    if (!isset($resultES['error']))
                        return $resultES;
                    else
                        return $resultES['error'];
                }

                $request->session()->put('esArrayIds', $resultES);
                return $resultES;
            } else {
                return false;
            }
        }
    }

    public static function deleteAndStoreFromAndToElasticsearch($dataSet,$filesArray)
    {
        foreach ($dataSet->files as $file) {
            if (!in_array($file->id, $filesArray)) {
                $index = 'refs';
                $type = 'ref';
                $id = $file->id;
                $Es_model = new Elasticsearch();
                $Es_model->deleteId($index, $type, $id);
            } else {
                //don't save images and no zips

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $info = finfo_file($finfo, storage_path() . '/app/' . 'YARMDBUploads' . '/' . $file['name']); // This will return the mime-type
                finfo_close($finfo);


                if (strpos($info, 'image') === false && strpos($info, '/zip') === false)
                    self::storeFilesToElasticSearch($file);
            }
        }
    }

    /**
     *
     */
    public static function upload2ElasticSearch($retryNotFound = false, $specificId = null)  //only use for Bulk-Upload
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
            list($allFields,$dataSet) = $es->createAllfields($ref);
            //$dataSet = $ref->prepareDataset();

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

            $citation = ExportController::reformatBladeExport(view('ydbviews.styles.format_as_' . strtolower(Style::getNameStyle()), $data)->render());

            try {
                $res = $es->createUpdateFile($index_name, $fileInfo, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget, $type,$allFields);
            } catch (\Throwable $e) {
                $fileToSave = File::find($id);
                $fileToSave->esearch = 'general error';
                $fileToSave->save();
                //echo('<p style=\"color:red;\"> Error with fileId =' . $id . ' - RefId = ' . $refId . '</p>');
                //continue;
            }

        }
    }

    static function updateAllFieldsElasticSearch()  //only use for Bulk-Upload
    {
       $i = 55000;
       $max = 60500;
        $es = new Elasticsearch();

        $index = 'refs';
        for ($i; $i <= $max; $i++) {
            $ref = Ref::find($i);
            if (isset($ref)) {
                try {
                    self::updateStoreToAllfieldsEL($es,$ref,$index);
                    echo($i);
                } catch (\Throwable $e) {
                    dd($e);
                }
            }
        }
        dd($searchResult = $es->searchInAllFields($index, 'Aafjes'));
    }

    public static function updateStoreToAllfieldsEL($es,$ref, $index) {
        $presentInES = $es->searchRefID($index, $ref);
        if (isset($presentInES) && count($presentInES['hits']['hits']) >= 1) {
            $success = $es->updateESforRefID($presentInES, $index, $ref);
        } else {
            $success = $es->storeNewESforRefId($index, $ref);
        }
        if ($success) return true;
        else return false;
    }

}
