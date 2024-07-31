<?php
namespace axenox\Sketch\Facades\Schemio;

use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Exceptions\FileNotFoundError;

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
    
    public function process(string $command, string $method, array $data) : array
    {
        $json = [
            "path" => '',
            "viewOnly" => false,
            "entries" => []
        ];
        switch (true) {
            case StringDataType::endsWith($command, '/list'):
                $path = StringDataType::substringBefore($command, '/list');
                $json = $this->list($path);
                break;
            case StringDataType::endsWith($command, '/art'):
                $json = []; // TODO
                break;
            case StringDataType::endsWith($command, '/docs') && $method === 'POST':
                $json = $this->writeDoc('', $data);
                break;
            case stripos($command, '/docs/') !== false:
                $filename = StringDataType::substringAfter($command, '/docs/');
                switch ($method) {
                    case 'POST':
                    case 'PUT':
                        $json = $this->writeDoc('', $data);
                        break;
                    case 'GET': 
                        $json = $this->readDoc('', $filename);
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
                'path' => $filePath
            ];
            switch (true) {
                case is_dir($file): 
                    $data['kind'] = 'dir'; 
                    $data['name'] = FilePathDataType::findFileName($filePath);
                    $data['children'] = [];
                    break;
                case StringDataType::endsWith($file, '.schemio.json'): 
                    $data['kind'] = 'schemio:doc'; 
                    $data['name'] = StringDataType::substringBefore(FilePathDataType::findFileName($filePath, true), '.schemio.json');
                    $data['id'] = $data['name']; // TODO add path here?
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
        
        $data['id'] = $data['name']; // TODO Add path here?
        
        $json = [
            "scheme" => $data,
            "folderPath" => ($path === '' ? null : $path),
            "viewOnly" => false
        ];
        // TODO Add description
        $path = $this->basePath . DIRECTORY_SEPARATOR . $data['name'] . '.schemio.json';
        file_put_contents($path, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        
        return $data;
    }
    
    protected function readDoc(string $path, string $name) : array
    {
        $filePath = $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $name . '.schemio.json';
        if (! file_exists($filePath)) {
            throw new FileNotFoundError('File not found');
        }
        return json_decode(file_get_contents($filePath), true);
    }
}