<?php

namespace Directus\Application\ErrorHandlers;

use Directus\Application\Http\Request;
use Directus\Application\Http\Response;
use Directus\Database\Exception\InvalidQueryException;
use Directus\Exception\BadRequestException;
use Directus\Exception\ErrorException;
use Directus\Exception\ForbiddenException;
use Directus\Exception\NotFoundException;
use Directus\Exception\UnauthorizedException;
use Directus\Hook\Emitter;
use Directus\Util\ArrayUtils;

class ErrorHandler
{
    /**
     * Hook Emitter Instance
     *
     * @var \Directus\Hook\Emitter
     */
    protected $emitter;

    /**
     * Error handler settings
     *
     * @var array
     */
    protected $settings;

    public function __construct($emitter = null, $settings = [])
    {
        // set_error_handler([$this, 'handleError']);
        // set_exception_handler([$this, 'handleException']);
        // register_shutdown_function([$this, 'handleShutdown']);

        if ($emitter && !($emitter instanceof Emitter)) {
            throw new \InvalidArgumentException(
                sprintf('Emitter must be a instance of \Directus\Hook\Emitter, %s passed instead', get_class($emitter))
            );
        }

        if (!is_array($settings)) {
            throw new \InvalidArgumentException('Settings must be an array');
        }

        $this->emitter = $emitter;
        $this->settings = $settings;
    }

    /**
     * Handles the error
     *
     * @param Request $request
     * @param Response $response
     * @param \Exception|\Throwable $exception
     *
     * @return Response
     */
    public function __invoke(Request $request, Response $response, $exception)
    {
        $data = $this->processException($exception);

        return $response
            ->withStatus($data['http_status_code'])
            ->withJson(['error' => $data['error']]);
    }

    /**
     * Returns an exception error and http status code information
     *
     * http_status_code and error key returned in the array
     *
     * @param \Exception|\Throwable $exception
     *
     * @return array
     */
    public function processException($exception)
    {
        $productionMode = ArrayUtils::get($this->settings, 'env', 'development') === 'production';
        $this->trigger($exception);

        $message = $exception->getMessage();
        // Not showing internal PHP errors (for PHP7) for production
        if ($productionMode && $this->isError($exception)) {
            $message = 'Internal Server Error';
        } else if (empty($message)) {
            $message = 'Unknown Error';
        }

        $httpStatusCode = 500;
        if ($exception instanceof BadRequestException) {
            $httpStatusCode = 400;
        } else if ($exception instanceof NotFoundException) {
            $httpStatusCode = 404;
        } else if ($exception instanceof UnauthorizedException) {
            $httpStatusCode = 401;
        } else if ($exception instanceof ForbiddenException) {
            $httpStatusCode = 403;
        }

        $data = [
            'code' => $exception->getCode(),
            'message' => $message
        ];

        if ($exception instanceof InvalidQueryException) {
            $data['query'] = $exception->getQuery();
        }

        if (!$productionMode) {
            $data = array_merge($data, [
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                // Do not output the trace
                // it can be so long or complex
                // that json_encode fails
                'trace' => $exception->getTrace(),
                // maybe as string, but let's get rid of them, for the best
                // and look at the logs instead
                // 'traceAsString' => $exception->getTraceAsString(),
            ]);
        }

        return [
            'http_status_code' => $httpStatusCode,
            'error' => $data
        ];
    }

    /**
     * Checks whether the exception is an error
     *
     * @param $exception
     *
     * @return bool
     */
    protected function isError($exception)
    {
        return $exception instanceof \Error
            || $exception instanceof \ErrorException
            || $exception instanceof ErrorException;
    }

    /**
     * Triggers application error event
     *
     * @param \Throwable $e
     */
    protected function trigger($e)
    {
        if ($this->emitter) {
            $this->emitter->run('application.error', $e);
        }
    }
}
