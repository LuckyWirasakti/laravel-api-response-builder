<?php
declare(strict_types=1);

namespace MarcinOrlowski\ResponseBuilder;

/**
 * Exception handler using ResponseBuilder to return JSON even in such hard tines
 *
 * @package   MarcinOrlowski\ResponseBuilder
 *
 * @author    Marcin Orlowski <mail (#) marcinOrlowski (.) com>
 * @copyright 2016-2019 Marcin Orlowski
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/MarcinOrlowski/laravel-api-response-builder
 */

use Exception;
use Illuminate\Auth\AuthenticationException as AuthException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class ExceptionHandlerHelper
 */
class ExceptionHandlerHelper
{
    /**
     * Exception types
     */
    public const TYPE_HTTP_NOT_FOUND_KEY           = 'http_not_found';
    public const TYPE_HTTP_SERVICE_UNAVAILABLE_KEY = 'http_service_unavailable';
    public const TYPE_HTTP_UNAUTHORIZED_KEY        = 'unauthorized_exception';
    public const TYPE_HTTP_EXCEPTION_KEY           = 'http_exception';
    public const TYPE_VALIDATION_EXCEPTION_KEY     = 'validation_exception';
    public const TYPE_UNCAUGHT_EXCEPTION_KEY       = 'uncaught_exception';
    public const TYPE_AUTHENTICATION_EXCEPTION_KEY = 'authentication_exception';

    /**
     * Render an exception into valid API response.
     *
     * @param \Illuminate\Http\Request $request   Request object
     * @param \Exception               $exception Exception
     *
     * @return HttpResponse
     */
    public static function render(/** @scrutinizer ignore-unused */ $request, Exception $exception): HttpResponse
    {
        $result = null;

        if ($exception instanceof HttpException) {
            switch ($exception->getStatusCode()) {
                case HttpResponse::HTTP_NOT_FOUND:
                    $result = static::error($exception, static::TYPE_HTTP_NOT_FOUND_KEY,
                        BaseApiCodes::EX_HTTP_NOT_FOUND(), HttpResponse::HTTP_NOT_FOUND);
                    break;

                case HttpResponse::HTTP_SERVICE_UNAVAILABLE:
                    $result = static::error($exception, static::TYPE_HTTP_SERVICE_UNAVAILABLE_KEY,
                        BaseApiCodes::EX_HTTP_SERVICE_UNAVAILABLE(), HttpResponse::HTTP_SERVICE_UNAVAILABLE);
                    break;

                case HttpResponse::HTTP_UNAUTHORIZED:
                    $result = static::error($exception, static::TYPE_HTTP_UNAUTHORIZED_KEY,
                        BaseApiCodes::EX_AUTHENTICATION_EXCEPTION(), HttpResponse::HTTP_UNAUTHORIZED);
                    break;

                default:
                    $result = static::error($exception, static::TYPE_HTTP_EXCEPTION_KEY,
                        BaseApiCodes::EX_HTTP_EXCEPTION(), HttpResponse::HTTP_BAD_REQUEST);
                    break;
            }
        } elseif ($exception instanceof ValidationException) {
            $result = static::error($exception, static::TYPE_VALIDATION_EXCEPTION_KEY,
                BaseApiCodes::EX_VALIDATION_EXCEPTION(), HttpResponse::HTTP_BAD_REQUEST);
        }

        if ($result === null) {
            $result = static::error($exception, static::TYPE_UNCAUGHT_EXCEPTION_KEY,
                BaseApiCodes::EX_UNCAUGHT_EXCEPTION(), HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $result;
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param \Illuminate\Http\Request                 $request
     * @param \Illuminate\Auth\AuthenticationException $exception
     *
     * @return HttpResponse
     */
    protected function unauthenticated(/** @scrutinizer ignore-unused */ $request,
                                                                         AuthException $exception): HttpResponse
    {
        return static::error($exception, static::TYPE_HTTP_UNAUTHORIZED_KEY, BaseApiCodes::EX_AUTHENTICATION_EXCEPTION());
    }

    /**
     * Process singe error and produce valid API response
     *
     * @param Exception $ex                   Exception to be handled.
     * @param string    $exception_config_key Type of the exception (TYPE_xxx)
     * @param integer   $fallback_api_code    API code to fall back to in provided api_code is invalid or out
     *                                        of range or not configured.
     * @param integer   $fallback_http_code   HTTP code to fallback to if provided http_code is invalid or out
     *                                        of range or not configured.
     *
     * @return HttpResponse
     */
    protected static function error(Exception $ex, $exception_config_key, $fallback_api_code,
                                    $fallback_http_code = ResponseBuilder::DEFAULT_HTTP_CODE_ERROR): HttpResponse
    {
        // common prefix for config key
        $base_key = sprintf('%s.exception', ResponseBuilder::CONF_EXCEPTION_HANDLER_KEY);

        // get API and HTTP codes from exception handler config or use fallback values if no such
        // config fields are set.
        $api_code = Config::get("{$base_key}.{$exception_config_key}.code", $fallback_api_code);
        $http_code = Config::get("{$base_key}.{$exception_config_key}.http_code", $fallback_http_code);

        // check if we now have valid HTTP error code for this case or need to make one up.
        if ($http_code < ResponseBuilder::ERROR_HTTP_CODE_MIN) {
            // no code, let's try to get the exception status
            $http_code = ($ex instanceof HttpException) ? $ex->getStatusCode() : $ex->getCode();
        }

        // can it be considered valid HTTP error code?
        if ($http_code < ResponseBuilder::ERROR_HTTP_CODE_MIN) {
            $http_code = $fallback_http_code;
        }

        // let's figure out what event we are handling now
        $known_codes = [
            self::TYPE_HTTP_NOT_FOUND_KEY           => BaseApiCodes::EX_HTTP_NOT_FOUND(),
            self::TYPE_HTTP_SERVICE_UNAVAILABLE_KEY => BaseApiCodes::EX_HTTP_SERVICE_UNAVAILABLE(),
            self::TYPE_UNCAUGHT_EXCEPTION_KEY       => BaseApiCodes::EX_UNCAUGHT_EXCEPTION(),
            self::TYPE_AUTHENTICATION_EXCEPTION_KEY => BaseApiCodes::EX_AUTHENTICATION_EXCEPTION(),
            self::TYPE_VALIDATION_EXCEPTION_KEY     => BaseApiCodes::EX_VALIDATION_EXCEPTION(),
            self::TYPE_HTTP_EXCEPTION_KEY           => BaseApiCodes::EX_HTTP_EXCEPTION(),
        ];
        $base_api_code = BaseApiCodes::NO_ERROR_MESSAGE();
        foreach ($known_codes as $item_config_key => $item_api_code) {
            if ($api_code === Config::get("{$base_key}.{$item_config_key}.code", $item_api_code)) {
                $base_api_code = $api_code;
                break;
            }
        }

        /** @var array|null $data Optional payload to return */
        $data = null;
        if ($api_code === Config::get("{$base_key}.validation_exception.code", BaseApiCodes::EX_VALIDATION_EXCEPTION())) {
            /** @var ValidationException $ex */
            $data = [ResponseBuilder::KEY_MESSAGES => $ex->validator->errors()->messages()];
        }

        $key = BaseApiCodes::getCodeMessageKey($api_code) ?? BaseApiCodes::getCodeMessageKey($base_api_code);

        // let's build error error_message
        $error_message = $ex->getMessage();

        // Check if we have dedicated message for this type of code for HttpException and its status code.
        if (($error_message === '') && ($ex instanceof HttpException)) {
            $key = sprintf('response-builder::builder.http_%d', $ex->getStatusCode());
            $error_message = Lang::get($key, ['api_code' => $api_code]);
        }

        // still nothing? if we do not have any error_message in the hand yet, we need to fall back to
        // built-in generic message for this type of exception
        if ($error_message === '') {
            $error_message = Lang::get($key, [
                'api_code' => $api_code,
                'message'  => get_class($ex),
            ]);
        }

        // if we have trace data debugging enabled, let's gather some debug info and add to the response.
        $trace_data = null;
        if (Config::get(ResponseBuilder::CONF_KEY_DEBUG_EX_TRACE_ENABLED, false)) {
            $trace_data = [
                Config::get(ResponseBuilder::CONF_KEY_DEBUG_EX_TRACE_KEY, ResponseBuilder::KEY_TRACE) => [
                    ResponseBuilder::KEY_CLASS => get_class($ex),
                    ResponseBuilder::KEY_FILE  => $ex->getFile(),
                    ResponseBuilder::KEY_LINE  => $ex->getLine(),
                ],
            ];
        }

        return ResponseBuilder::errorWithMessageAndDataAndDebug($api_code, $error_message, $data,
            $http_code, null, $trace_data);
    }
}
