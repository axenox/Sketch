<?php
namespace axenox\Sketch\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use function GuzzleHttp\Psr7\stream_for;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;

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
        
        $baseUrl = $this->getWorkbench()->getUrl();
        $appUrl = $baseUrl . $this->getUrlRouteDefault();
        
        // api/schemio/index.html/ -> index.html
        $pathInFacade = mb_strtolower(StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/'));
        
        // Do the routing here
        switch (true) {     
            case StringDataType::endsWith($pathInFacade, 'schemio.app.js'):
                $filePath = $filePath = $this->getFilePath($pathInFacade);
                $body = file_get_contents($filePath);
                $body = str_replace([
                    'rootPath: "/",',
                    'assetsPath: "/assets",',
                    '"/v1/'
                ], [
                    'rootPath: "' . $appUrl . '",',
                    'assetsPath: "' . $appUrl . '/assets",',
                    '"v1/'
                ], $body);
                $headers['Content-Type'] = 'text/javascript';
                $responseCode = 200;
                break;
            case StringDataType::endsWith($pathInFacade, '.html') && $pathInFacade !== 'index.html':
            case StringDataType::endsWith($pathInFacade, '.css'):
            case StringDataType::endsWith($pathInFacade, '.js'):
            case StringDataType::endsWith($pathInFacade, '.png'):
            case StringDataType::endsWith($pathInFacade, '.woff2'):
            case StringDataType::endsWith($pathInFacade, '.tff'):
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
            case StringDataType::startsWith($pathInFacade, 'v1/fs'):
                $json = [
                    "path" => "",
                    "viewOnly" => false,
                    "entries" => []
                ];
                $body = json_encode($json);
                $headers['Content-Type'] = 'application/json';
                $responseCode = 200;
                break;
            case $pathInFacade === null:
            case $pathInFacade === '':
            case $pathInFacade === 'index.html':
            default:
                $filePath = $this->getFilePath('html' . DIRECTORY_SEPARATOR . 'index.html');
                $body = file_get_contents($filePath);
                $type = MimeTypeDataType::findMimeTypeOfFile($filePath);
                $headers['Content-Type'] = $type;
                $responseCode = 200;
                break;
                
        }
        
        return new Response(($responseCode ?? 404), $headers, stream_for($body ?? ''));
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
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
     */
    protected function getMiddleware() : array
    {
        $middleware = parent::getMiddleware();
        
        // Add HTTP basic auth for simpler API testing. This allows to log in with
        // username and password from API clients like PostMan.
        $middleware[] = new AuthenticationMiddleware($this, [
              [AuthenticationMiddleware::class, 'extractBasicHttpAuthToken']
        ]);
        
        return $middleware;
    }
}