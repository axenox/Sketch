<?php
namespace axenox\Sketch\Facades;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use axenox\Sketch\Facades\Schemio\SchemioFs;

/**
 * 
 * @author andrej.kabachnik
 *
 */

class SchemioFacade extends AbstractHttpFacade
{
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $headers = $this->buildHeadersCommon();
        
        // api/schemio/index.html/ -> index.html
        $pathInFacade = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/');
        list($appVendor, $appAlias, $pathInFacade) = explode('/', $pathInFacade, 3);
        
        $baseUrl = $this->getWorkbench()->getUrl();
        $schemioUrl = $baseUrl . $this->getUrlRouteDefault() . '/' . $appVendor . '/' . $appAlias;
        
        // Do the routing here
        switch (true) {     
            // main app script
            case StringDataType::endsWith($pathInFacade, 'schemio.app.js'):
                $filePath = $filePath = $this->getFilePath($pathInFacade);
                $body = file_get_contents($filePath);
                $headers['Content-Type'] = 'text/javascript';
                $responseCode = 200;
                break;
                
            // e.g. assets/templates/index.json
            case StringDataType::endsWith($pathInFacade, 'index.json'):
                $filePath = $filePath = $this->getFilePath($pathInFacade);
                $body = file_get_contents($filePath);
                $headers['Content-Type'] = 'text/javascript';
                $responseCode = 200;
                break;
                
            // Static assets
            case StringDataType::endsWith($pathInFacade, '.html') && $pathInFacade !== 'index.html':
            case StringDataType::endsWith($pathInFacade, '.css'):
            case StringDataType::endsWith($pathInFacade, '.js'):
            case StringDataType::endsWith($pathInFacade, '.png'):
            case StringDataType::endsWith($pathInFacade, '.woff2'):
            case StringDataType::endsWith($pathInFacade, '.tff'):
            case StringDataType::endsWith($pathInFacade, '.svg'):
            case StringDataType::endsWith($pathInFacade, '.json'):
                $folder = StringDataType::endsWith($pathInFacade, '.html') ? 'html' : '';
                $filePath = $this->getFilePath($folder . DIRECTORY_SEPARATOR . $pathInFacade);
                if ($filePath !== null) {
                    $body = file_get_contents($filePath);
                    $type = MimeTypeDataType::guessMimeTypeOfExtension(FilePathDataType::findExtension($pathInFacade));
                    $headers['Content-Type'] = $type;
                    $responseCode = 200;
                } else {
                    $responseCode = 404;
                }
                break;
             
            // API
            case StringDataType::startsWith($pathInFacade, 'v1/fs'):
                $fsBase = $this->getWorkbench()->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $appVendor . DIRECTORY_SEPARATOR . $appAlias;
                $fsPath = StringDataType::substringAfter($pathInFacade, 'v1/fs');
                $fs = new SchemioFs($fsBase);
                $requestBody = $request->getBody()->__toString(); 
                $json = $fs->process($fsPath, $request->getMethod(), ($requestBody === '' ? [] : json_decode($requestBody, true)), $request->getQueryParams());
                $body = json_encode($json);
                $headers['Content-Type'] = 'application/json';
                $responseCode = 200;
                break;
            
            // index.html
            case $pathInFacade === null:
            case $pathInFacade === '':
            case $pathInFacade === 'index.html':
            default:
                $filePath = $this->getFilePath('html' . DIRECTORY_SEPARATOR . 'index-server.tpl.html');
                $body = file_get_contents($filePath);
                $routePrefix = '/' . trim((new Uri($schemioUrl))->getPath(), "/");
                $body = str_replace([
                        '{{ routePrefix }}'
                    ], [
                        $routePrefix
                    ], 
                    $body
                );
                $type = MimeTypeDataType::findMimeTypeOfFile($filePath);
                $headers['Content-Type'] = $type;
                $responseCode = 200;
                break;
                
        }
        
        return new Response(($responseCode ?? 404), $headers, Utils::streamFor($body ?? ''));
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/schemio';
    }
    
    /**
     * 
     * @param string $pathInFacade
     * @param string $subfolder
     * @return string|NULL
     */
    protected function getFilePath(string $pathInFacade, string $subfolder = 'Facades' . DIRECTORY_SEPARATOR . 'Schemio') : ?string
    {
        $path = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . $subfolder . DIRECTORY_SEPARATOR . $pathInFacade;
        if (file_exists($path) === true) {
            return new \SplFileInfo($path);
        }
        return null;
    }
    
    /**
     *
     * @return array
     */
    protected function buildHeadersCommon() : array
    {
        $headers = parent::buildHeadersCommon();
        // TODO add some headers here if required, e.g.
        // $headers['Content-Type'] = 'text/html';
        return $headers;
    }
}