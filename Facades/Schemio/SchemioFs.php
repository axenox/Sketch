<?php
namespace axenox\Sketch\Facades\Schemio;

use DateTime;
use Exception;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Exceptions\DataSheets\DataSheetDeleteError;
use exface\Core\Exceptions\FileNotFoundError;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Exceptions\FileNotReadableError;
use League\OpenAPIValidation\Schema\TypeFormats\StringDate;
use Symfony\Component\VarDumper\Exception\ThrowingCasterException;

class SchemioFs
{
    const FILE_SUFFIX = '.schemio.json';

    private $basePath = '';

    public function __construct(string $basePath)
    {
        if (!StringDataType::endsWith($basePath, 'Sketches')) {
            $basePath = $basePath . DIRECTORY_SEPARATOR . 'Sketches';
            if (!is_dir($basePath)) {
                Filemanager::pathConstruct($basePath);
            }
        }
        $this->basePath = $basePath;
    }

    public function process(string $command, string $method, array $data, array $params): array
    { 
        $json = [
            "path" => '',
            "viewOnly" => false,
            "entries" => []
        ];
        // Routing similarly to https://github.com/ishubin/schemio/blob/master/src/server/server.js
        switch (true) {
            // /v1/fs/list -> fsListFilesRoute
            case StringDataType::endsWith($command, '/list'):
                $json = $this->listDir('');
                break;
            // /v1/fs/list/* -> fsListFilesRoute
            case StringDataType::startsWith($command, '/list/'):
                $path = StringDataType::substringAfter($command, '/list/');
                $json = $this->listDir($path);
                break;
            // /v1/fs/ -> fsCreateArt
            case StringDataType::endsWith($command, '/art'):
                $json = []; // TODO
                break;
            // /v1/fs/dir
            case StringDataType::endsWith($command, '/dir'):
                switch ($method) {
                    // fsCreateDirectory
                    case 'POST':
                        $json = $this->createDirectory($data);
                        break;
                    case 'PUT':
                        break;
                    case 'GET':
                        break;
                    // fsDeleteDirectory
                   case 'DELETE':
                        $param = $params['path'];   
                        $param = $this-> getIdFromFilePath($param);
                        $json = $this->deleteFile($param, false);
                        break;
                    // fsPatchDirectory
                    case 'PATCH':
                        $param = $params['path'];   
                        $param = $this-> getIdFromFilePath($param);
                        //TODO $json = $this->renameDirectory($param, $data);
                    break;
                }
                break; 
            case StringDataType::endsWith($command, '/docs'):
                switch ($method) {
                    // /v1/fs/docs
                    case 'POST':                       
                        $path = $params['path'] ?? '';
                        $json = $this->writeDoc($path, $data);
                        break;
                    case 'PUT':
                        $json = $this->writeDoc('', $data);
                        break;
                    case 'GET':
                        $json = $this->listAll('*' . self::FILE_SUFFIX, $params['q'] ?? null);
                        break;
                }
                break;

            // /docs/<file or id>
            case stripos($command, '/docs/') !== false:
                $id = StringDataType::substringAfter($command, '/docs/');
                switch ($method) {
                    case strpos($method, 'DELETE') !== false:
                        $id = StringDataType::substringAfter($command, '/docs/');
                        $this-> deleteDoc(FilePathDataType::normalize($id, DIRECTORY_SEPARATOR)); 
                        break;
                    // /v1/fs/docs/:schemeId
                    case 'POST':
                    case 'PUT':
                        // fsSaveScheme --> calls when user clicked SAVE button
                        $json = $this->writeDocById($id, $data);
                        break;
                    case 'GET': 
                        $json = $this->readDocById($id);
                        break;
                    // v1/fs/docs
                    // fsPatchScheme 
                    case 'PATCH':
                        $json = $this->renameSchemeById($id, $data);
                        break;

                    // see delete /v1/fs/docs/:schemeId
                    case 'DELETE': //todo
                        $filename = StringDataType::substringAfter($command, '/docs');
                        $json = $this->deleteDoc($filename);
                        break;
                }
        }
        return $json;
    }
      
     /**
     * Deletes the directory and subfiles
     * It calls the 'deleteFile' function recursively to delete nested subfolders. If 'forceDelete' (nested delete) is true, subfolders are also deleted.
     * @param string $base64Url
     * @param bool $forceDelete
     * @return array
     */
    function deleteFile(string $base64Url, bool $forceDelete = false) : array { 
        $result = [];
        $pathname = $this->getFilePathFromId($base64Url);
        $path = FilePathDataType::findFolderPath($pathname);
     
        $directoryPath = $this->basePath; 
        if (!$forceDelete) {
            $fullPath = rtrim($directoryPath, '/') . '/' . trim($pathname, '/');
        }
        else{
            $fullPath = $pathname;
        }

        $fixedPath = str_replace('\\', '/', $fullPath);
    
        if (is_dir($fixedPath)) {
            // Get files and subfolders in folder
            $files = array_diff(scandir($fixedPath), ['.', '..']);
            
            foreach ($files as $file) {
                $forceDelete = true;
                $filePath = $fixedPath . '/' . $file;
                if (is_dir($filePath)) {
                    // If this is a folder, call deleteFile recursively to delete its contents
                    if ($forceDelete !== false) {
                        $this->deleteFile($this->getIdFromFilePath($filePath), true);
                    } else {
                        $result['status'] = 'Error';
                        $result['message'] = 'Cannot delete folder: It contains subfolders and force delete is not enabled.';
                        return $result;
                    }
                } else {
                    // If this is a file, delete it
                    unlink($filePath);
                }
            }
    
            // Delete this folder after all files and subfolders are deleted
            if (count(array_diff(scandir($fixedPath), ['.', '..'])) == 0) {
                if (rmdir($fixedPath)) {
                    $result['status'] = 'Success';
                    $result['message'] = 'The folder and its contents were successfully deleted.';
                } else {
                    $result['status'] = 'Error';
                    $result['message'] = 'An error occurred while deleting the folder.';
                }
            } else {
                $result['status'] = 'Error';
                $result['message'] = 'The folder could not be deleted because it is not empty.';
            }
        } else {
            $result['status'] = 'Error';
            $result['message'] = 'Invalid path.';
        }
    
        return $result; 
    }
    
    function deleteDoc(string $base64Url) : array {
        $result = [];

        $pathname = $this->getFilePathFromId($base64Url);
        $path = FilePathDataType::findFolderPath($pathname);
 
        $directoryPath = $this->basePath; 
        $fullPath = rtrim($directoryPath, '/') . '/' .trim($pathname,'/');
        $fixedPath = str_replace('\\', '/', $fullPath);
    
        if (is_file($fixedPath)) { 
            if (unlink($fixedPath)) {
                $result['status'] = 'Success';
                $result['message'] = 'File deleted successfully'; 
            } else {
                $result['status'] = 'Error';
                $result['message'] = 'Failed to delete file '; 
            }
        } else {
            $result['status'] = 'Error';
            $result['message'] = 'Invalid path'; 
        }
        
        return []; 
    }


    /**
     * Lists all files and folders in the selected directory
     * @param string $path 
     * @return array
     */
    protected function listDir(string $path, string $pattern = '*') : array
    {
        $abs = $this->basePath . DIRECTORY_SEPARATOR . FilePathDataType::normalize($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = glob($abs . $pattern);
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
                'path' => $pathname,
                'modifiedTime' => $this->getFileMTime($file)
            ];
            switch (true) {
                case is_dir($file): 
                    $data['kind'] = 'dir'; 
                    $data['name'] = FilePathDataType::findFileName($filePath);
                    $data['id'] = $this->getIdFromFilePath($pathname);
                    $data['children'] = [];
                    $data['parents'] = $parents;
                    break;
                case StringDataType::endsWith($file, self::FILE_SUFFIX): 
                    $filename = FilePathDataType::findFileName($filePath, true);
                    $docName = $this->getDocnameFromFilename($filename);
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

    /**
     * Lists all documents - e.g. for references or search
     *
     * @param string $filenamePattern
     * @param string|null $searchQuery
     * @return array
     */
    protected function listAll(string $filenamePattern, string $searchQuery = null) : array
    {
        $abs = $this->basePath . DIRECTORY_SEPARATOR;
        $files = $this->listFiles($abs, $filenamePattern, true);
        $json = [];

        foreach ($files as $file) {
            $pathname = FilePathDataType::normalize(StringDataType::substringAfter($file, $abs), '/');
            $fileId = $this->getIdFromFilePath($pathname);
            $filename = FilePathDataType::findFileName($pathname, true);
            $docName = $this->getDocnameFromFilename($filename);
            // If there is a search query, check if it matches the document name or its
            // contents. Otherwise ignore this file
            if ($searchQuery !== null) {
                if (stripos($docName, $searchQuery) === false) {
                    $fileContents = file_get_contents($file);
                    if ($fileContents === false || stripos($fileContents, $searchQuery) === false) {
                        continue;
                    }
                }
            }
            $data = [
                'fsPath' => $pathname,
                'id' => $fileId,
                'link' => '/docs/' . $fileId,
                'lowerName' => mb_strtolower($docName),
                'name' => $docName,
                'modifiedTime' => $this->getFileMTime($pathname)
            ];
            $json[] = $data;
        }
        return [
            "kind" => 'page',
            "page" => 1,
            "results" => $json,
            "totalPages" => 1,
            "totalResults" => count($json)
        ];
    }

    /**
     * Returns an array with file/folder absolute paths from a given path
     *
     * @param string $path
     * @param string $pattern
     * @param boolean $recursive
     * @param integer $globFlags
     * @return string[]
     */
    protected function listFiles(string $path, string $pattern = '*', bool $recursive = false, int $globFlags = 0) : array
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($recursive === true) {
            // Read files
            $files = glob($path . $pattern, $globFlags); 
            $subdirs = glob($path . '*', GLOB_ONLYDIR|GLOB_NOSORT);
            foreach ($subdirs as $dir) {
                $files = array_merge(
                    $files, 
                    $this->listFiles($dir, $pattern, $recursive, $globFlags)
                );
            }
        } else {
            $files = glob($path . $pattern, $globFlags);
        }
        $files = array_filter($files, 'is_file');
        return $files;
    }

    /**
     * The user clicks the button to create/save a new diagram. The file name and description are entered and Create is pressed.
     * @param string $path
     * @param array $data
     * @return array
     */
    protected function writeDoc(string $path, array $data) : array
    {
        $filename = $this->getFilenameFromDocname($data['name']);
        $data['id'] = $this->getIdFromFilePath($path . '/' . $filename);
        $path = $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return $data;
    }

    /**
     * Rename Document
     * @param string $path
     * @param array $data
     * @return array
     */
    protected function renameFile(string $path, string $oldname, array $data) : array
    {
        $oldname = ltrim(FilePathDataType::normalize($oldname), '/');
        $newname = $this->getDocnameFromFilename($data['name']);
  
        $oldpath =  $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $oldname;
        $oldpath = FilePathDataType::normalize($oldpath, DIRECTORY_SEPARATOR);
        $newpath = $this->basePath . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $newname;
        $newpath = FilePathDataType::normalize($newpath);
     
        if (rename($oldpath,$newpath)) {
            return [  
                "status" => 'ok'
            ];
        } else {
            throw new \Exception('Failed to rename diagram');
        }
        
       
    }

    /**
     * @param $base64Url
     * @param $data
     * @return array
    */
    protected function writeDocById(string $base64Url, array $data) : array
    {
        $pathname = $this->getFilePathFromId($base64Url);
        $path = FilePathDataType::findFolderPath($pathname);
        
        return $this->writeDoc($path, $data);
    }

     /**
     * Renames the Schemio file via id
     * if you use the same function for renaming files, it renames the file but it not shows the change on the page, after manual page refresh it shows the change
     * TODO do seperate function for each deletion type
     * @param $base64Url
     * @param $data
     * @return array
    */
    protected function renameSchemeById(string $base64Url, array $data) : array
    {
        $oldname = $this->getFilePathFromId($base64Url);
        $path = FilePathDataType::findFolderPath($oldname); 

        return $this->renameFile($path, $oldname, $data);
    }
    
    /**
     * Opens the selected Schemio file using its id value.
     * @param string $base64Url 
     * @return array
    */
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
        $scheme = json_decode($json, true);
        if ($scheme === null) {
            throw new FileNotReadableError('Cannot read "' . $pathname . '"');
        }
        
        // Update data to make sure it matches the provided id (in case the file
        // was modified/broken by git operations or copied manually).
        $filename = FilePathDataType::findFileName($pathname, true);
        $scheme['id'] = $base64Url;
        $scheme['name'] = $this->getDocnameFromFilename($filename);
        $doc = [
            'folderPath' => FilePathDataType::findFolderPath($pathname),
            'id' => $base64Url,
            'modifiedTime' => $this->getFileMTime($filePath),
            'scheme' => $scheme
        ];
        // Return the consitent document structure
        return $doc;
    }

    /**
     * 
     * @param string $path 
     * @param string $name 
     * @return array
    */
    protected function readDoc(string $path, string $name): array
    {
        $pathname = $path . '/' . $this->getFilenameFromDocname($name);
        $filePath = str_replace('\\\\', '\\', $this->basePath . DIRECTORY_SEPARATOR . FilePathDataType::normalize($pathname, DIRECTORY_SEPARATOR));
        if (!file_exists($filePath)) {
            throw new FileNotFoundError('File not found');
        }
        $scheme = json_decode(file_get_contents($filePath), true);
        $doc = [
            'folderPath' => FilePathDataType::findFolderPath($pathname),
            'id' => $scheme['id'],
            'modifiedTime' => $this->getFileMTime($filePath),
            'scheme' => $scheme
        ];
        return $doc;
    }

    /**
     * Creates the Directory
     * 
     * @param string $path
     * @param array $data
     * @return array
    */
    protected function createDirectory(array $data): array
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

    /**
     * Strips the `.schemio.json` suffix from a file name if it is there
     *
     * @param string $filename
     * @return string
     */
    protected function getDocnameFromFilename(string $filename) : string
    {
        return StringDataType::substringBefore($filename, self::FILE_SUFFIX, $filename);
    }

    /**
     * Returns the file name for a given scheme name - i.g. with the `.schemio.json` suffix
     *
     * @param string $docName
     * @return string
     */
    protected function getFilenameFromDocname(string $docName) : string
    {
        return $docName . self::FILE_SUFFIX;
    }

    /**
     * Returns ISO 8601 formatted date and time for the given file absolute path
     *
     * @param string $absPath
     * @return string|null
     */
    protected function getFileMTime(string $absPath) : ?string
    {
        if (false !== $mtime = filemtime($absPath)) {
            return (new DateTime('@' . $mtime))->format('c');
        }
        return null;
    }
}