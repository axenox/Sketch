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
        if (! StringDataType::endsWith($basePath, 'Sketches')) {
            $basePath = $basePath . DIRECTORY_SEPARATOR . 'Sketches';
            if (! is_dir($basePath)) {
                Filemanager::pathConstruct($basePath);
            }
        }
        $this->basePath = $basePath;
    }
    
    public function process(string $command, string $method, array $data, array $params = []) : array
    {
        $json = [
            "path" => '',
            "viewOnly" => false,
            "entries" => []
        ];
        // Routing similarly to https://github.com/ishubin/schemio/blob/master/src/server/server.js
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
            case StringDataType::endsWith($command, '/docs') && $method === 'POST':
                $json = $this->writeDoc('', $data, $params);
                break;
            // /v1/fs/docs/:docId
            case stripos($command, '/docs/') !== false:
                $id = StringDataType::substringAfter($command, '/docs/');
                switch ($method) {
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
    
    protected function list(string $path) : array
    {
        $abs = $this->basePath . DIRECTORY_SEPARATOR . FilePathDataType::normalize($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = glob("{$abs}*");
        $json = [];
        foreach ($files as $file) {
            $filePath = FilePathDataType::normalize(StringDataType::substringAfter($file, $abs), '/');
            $data = [
                'path' => $path . '/' . $filePath
            ];
            switch (true) {
                case is_dir($file): 
                    $data['kind'] = 'dir'; 
                    $data['name'] = FilePathDataType::findFileName($filePath);
                    $data['children'] = [];
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
            "path" => $path,
            "viewOnly" => false,
            "entries" => $json
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
        $path = FilePathDataType::findFolder($pathname);
        
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
        $doc['folderPath'] = FilePathDataType::findFolder($pathname);
        $doc['id'] = $base64Url;
        if (array_key_exists('scheme', $doc)) {
            $filename = FilePathDataType::findFileName($pathname, true);
            $doc['scheme']['id'] = $base64Url;
            $doc['scheme']['name'] = StringDataType::substringBefore($filename, '.schemio.json', $filename);
        }
        
        // Return the consitent document structure
        return $doc;
    }
    
    protected function readDoc(string $path, string $name) : array
    {
        $filePath = $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $name . '.schemio.json';
        if (! file_exists($filePath)) {
            throw new FileNotFoundError('File not found');
        }
        return json_decode(file_get_contents($filePath), true);
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