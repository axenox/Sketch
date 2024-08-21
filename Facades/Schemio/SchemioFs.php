<?php
namespace axenox\Sketch\Facades\Schemio;

use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Exceptions\FileNotFoundError;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\Exceptions\FileNotReadableError;

class SchemioFs
{
    private $basePath = '';

    public function __construct(string $basePath)
    {
        if (!StringDataType::endsWith($basePath, 'Sketches')) {
            $basePath = $basePath . DIRECTORY_SEPARATOR . 'Sketches';
            if (!is_dir($basePath)) {
                Filemanager::pathConstruct($basePath);
            }
        }
        $this->basePath = $basePath; //folderım into this path
    }

    public function process(string $command, string $method, array $data, array $params): array
    { 
        $json = [
            "path" => '',
            "viewOnly" => false,
            "entries" => []
        ];
        // Routing similarly to https://github.com/ishubin/schemio/blob/master/src/server/server.j
        switch (true) {
            // /v1/fs/list
            case StringDataType::endsWith($command, '/list'):
                $json = $this->list('');
                break;
            // /v1/fs/list/*
            case StringDataType::startsWith($command, '/list/'):
                $path = StringDataType::substringAfter($command, '/list/');
                $json = $this->list($path);
                break;
            case StringDataType::endsWith($command, '/art'):
                $json = []; // TODO
                break;
            case StringDataType::endsWith($command, '/dir'):
                switch ($method) {
                    case 'POST':
                        $json = $this->createDirectory('', $data);
                        break;
                    case 'PUT':
                        break;
                    case 'GET':
                        break;
                }
                break;
            // /v1/fs/docs/:docId
            case StringDataType::endsWith($command, '/docs'):
                switch ($method) {
                    case 'POST':
                       
                        $path = $params['path'] ?? '';

                        $json = $this->writeDoc($path, $data);
                        break;
                    case 'PUT':
                        $json = $this->writeDoc('', $data);
                        break;
                    case 'GET':
                        $filename = StringDataType::substringAfter($command, '/docs');
 
                        $json = $this->readDoc('', $filename);
                        break;
                }
                break;

            // /docs/<file or id>
            case stripos($command, '/docs/') !== false:
                // $filename = StringDataType::substringAfter($command, '/docs/');
                $id = StringDataType::substringAfter($command, '/docs/');
                switch ($method) {
                    case strpos($method, 'DELETE') !== false:
                        
                        $param = $params['query']; 
                        parse_str($param, $queryArray);
                        $path = $queryArray['path'] ?? '';
 
                        $test = FilePathDataType::normalize(rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command , DIRECTORY_SEPARATOR);
                        $this-> deleteFile(FilePathDataType::normalize($test,DIRECTORY_SEPARATOR)); 
                    case 'POST':

                    case 'PUT':
                        $json = $this->writeDocById($id, $data);
                        break;
                    case 'GET': 
                        $json = $this->readDocById($id);
                        break;
                }
        }
        return $json;
    }
 
    /**
     * TODO
     * 
     * @param mixed $filePath
     * @throws \Exception
     * @return bool
     */
    function deleteFile(string $filePath) : bool
    { 
        $directoryPath = $this->basePath;
        $directoryPath = $_POST['directoryPath'] ?? $directoryPath;
        $filePath = "C:/wamp64/www/exface/vendor/axenox/sketch/Sketches/b.schemio.json"; //TODO
        if ($filePath && file_exists($filePath)) {
            if (unlink($filePath)) { 
                return $this->deleteFromFileIndex($filePath);
            } else {
                throw new \Exception("During the deletion, an error accoured: $filePath");
            }
        } else {
            throw new \Exception("File does not exist: $filePath");
        }
    }

    /**
     * TODO 
     * 
     * @return void
     */
    protected function deleteFolder()
    {

    }

    protected function list(string $path) : array
    {
        $abs = $this->basePath . DIRECTORY_SEPARATOR . FilePathDataType::normalize($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = glob("{$abs}*");
        $json = [];
           
        $parents = [];
        $crumbPath = '/';
        $crumbs = explode('/', $path ?? '');
        foreach ($crumbs as $crumb) {
            if ($crumb === '') {
                continue;
            }
            $crumbPath .= ($crumbPath !== '' ? '/' : '') . $crumb;
            $parents[] = $this->getIdFromFilePath($crumbPath);
        }
        
        if($path !== '' && $path !== '/'){
            $parent = FilePathDataType::findFolderPath($path);
            $parent = $parent === '\\' ? '/' :$parent;
            $json[] = [
                'path' => $parent,
                'kind' => 'dir',
                'name' => '..',
                'id' => $this->getIdFromFilePath($parent),
                'children' => [],
                'parents' => $parents
            ];
        }

        foreach ($files as $file) {
            $filePath = FilePathDataType::normalize(StringDataType::substringAfter($file, $abs), '/');
            $pathname = $path . '/' . $filePath;
            $data = [
                'path' => $pathname
            ];
            switch (true) {
                case is_dir($file): 
                    $data['kind'] = 'dir'; 
                    $data['name'] = FilePathDataType::findFileName($filePath);
                    $data['id'] = $this->getIdFromFilePath($pathname);
                    $data['children'] = [];
                    $data['parents'] = $parents;
                    break;
                case StringDataType::endsWith($file, '.schemio.json'): 
                    $filename = FilePathDataType::findFileName($filePath, true);
                    $docName = StringDataType::substringBefore($filename, '.schemio.json');
                    $data['kind'] = 'schemio:doc'; 
                    $data['name'] = $docName;
                    $data['id'] = $this->getIdFromFilePath($path . '/' . $filename);
                    break;
            }
            $json[] = $data;
        }
        return [
            "id" => $this->getIdFromFilePath($path ?? ''),
            "path" => $path,
            "viewOnly" => false,
            "entries" => $json,
            "parents" => $parents
        ];
    }

    protected function writeDoc(string $path, array $data) : array
    {
        $filename = $data['name'] . '.schemio.json';
        $data['id'] = $this->getIdFromFilePath($path . '/' . $filename); // TODO Add path here?
        
        $json = [
            "scheme" => $data,
            "folderPath" => ($path === '' ? null : $path),
            "viewOnly" => false
        ];
        // TODO Add description
        $path = $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        
        return $data;
    }

    protected function writeDocById(string $base64Url, array $data) : array
    {
        $pathname = $this->getFilePathFromId($base64Url);
        $path = FilePathDataType::findFolderPath($pathname);
        
        return $this->writeDoc($path, $data);
    }
    
    protected function readDocById(string $base64Url) : array
    {
        $pathname = $this->getFilePathFromId($base64Url);
        $filePath = $this->basePath . DIRECTORY_SEPARATOR . $pathname;
            
        // Read the file
        if (! file_exists($filePath)) {
            throw new FileNotFoundError('File not found: "' . $pathname . '"');
        }
        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new FileNotReadableError('Cannot read "' . $pathname . '"');
        }
        $doc = json_decode($json, true);
        if ($doc === null) {
            throw new FileNotReadableError('Cannot read "' . $pathname . '"');
        }
        
        // Update data to make sure it matches the provided id (in case the file
        // was modified/broken by git operations or copied manually).
        // $path = FilePathDataType::findFolderPath($pathname);
        $doc['folderPath'] = FilePathDataType::findFolderPath($pathname);
        $doc['id'] = $base64Url;
        if (array_key_exists('scheme', $doc)) {
            $filename = FilePathDataType::findFileName($pathname, true);
            $doc['scheme']['id'] = $base64Url;
            $doc['scheme']['name'] = StringDataType::substringBefore($filename, '.schemio.json', $filename);
        }
        // Return the consitent document structure
        return $doc;
    }

    protected function readDoc(string $path, string $name): array
    {
        $filePath = str_replace('\\\\', '\\', $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $name . '.schemio.json');
        if (!file_exists($filePath)) {
            throw new FileNotFoundError('File not found');
        }
        return json_decode(file_get_contents($filePath), true);
    }

    protected function createDirectory(string $path, array $data): array
    {
        $parent_directory = $data['path'] ?? '';
        $new_directory = $data['name'] ?? '';


        $directoryPath = $this->basePath;
        $directoryPath = $_POST['directoryPath'] ?? $directoryPath;


        $fullPath = rtrim($directoryPath, '/') . '/' . trim($parent_directory, '/') . '/' . $new_directory;

        $permissions = 0755;


        if (!is_dir($fullPath)) {
            if (mkdir($fullPath, $permissions, true)) {

            } else {
                throw new FileNotFoundError('Failed to create directory 1');
            }
        } else {
            // return $data;
            throw new FileNotFoundError("Test");
            // return [
            //     'error'=> 'Directory already exists'
            // ];

            // throw new FileNotFoundError('Directory already exists 1');
        }

        $data['id'] = $new_directory;
        $data['kind'] = 'dir';
        $data['path'] = $parent_directory . '/' . $new_directory;


        return $data;
    }

    /**
     * Encodes the given relative path (relative to the Sketches/ folder) as Base64URL
     * 
     * These ids are used by the frontend as parts of the URL to read and write
     * documents. It seems, the id can be anything, but it must be URL compatible.
     * This is why we use Base64URL here (Base64 itself is not URL compatible).
     * 
     * @param string $pathname
     * @return string
     */
    protected function getIdFromFilePath(string $pathname) : string
    {
        return BinaryDataType::convertTextToBase64URL($pathname);
    }
    
    /**
     * 
     * @param string $base64Url
     * @return string
     */
    protected function getFilePathFromId(string $base64Url) : string
    {
        return BinaryDataType::convertBase64URLToText($base64Url);
    }
}