<?php

namespace Telegram\Bot\Http;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Telegram\Bot\Exceptions\CouldNotUploadInputFile;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Helpers\Validator;
use Telegram\Bot\Traits\HasAccessToken;

/**
 * Class TelegramClient.
 */
class TelegramClient
{
    use HasAccessToken;

    /** @var string Telegram Bot API URL. */
    protected string $baseBotUrl = 'https://api.telegram.org/bot';

    /** @var HttpClientInterface|null HTTP Client. */
    protected ?HttpClientInterface $httpClientHandler = null;

    /** @var bool Indicates if the request to Telegram will be asynchronous (non-blocking). */
    protected bool $isAsyncRequest = false;

    /** @var int Timeout of the request in seconds. */
    protected int $timeOut = 60;

    /** @var int Connection timeout of the request in seconds. */
    protected int $connectTimeOut = 10;

    /** @var TelegramResponse|null Stores the last request made to Telegram Bot API. */
    protected ?TelegramResponse $lastResponse;

    /**
     * Instantiates a new TelegramClient object.
     *
     * @param HttpClientInterface|null $httpClientHandler
     */
    public function __construct(HttpClientInterface $httpClientHandler = null)
    {
        $this->httpClientHandler = $httpClientHandler ?? new GuzzleHttpClient();
    }

    /**
     * Returns the HTTP client handler.
     *
     * @return HttpClientInterface
     */
    public function getHttpClientHandler(): HttpClientInterface
    {
        return $this->httpClientHandler ??= new GuzzleHttpClient();
    }

    /**
     * Sets the HTTP client handler.
     *
     * @param HttpClientInterface $httpClientHandler
     *
     * @return $this
     */
    public function setHttpClientHandler(HttpClientInterface $httpClientHandler): self
    {
        $this->httpClientHandler = $httpClientHandler;

        return $this;
    }

    /**
     * Get the Base Bot API URL.
     *
     * @return string
     */
    public function getBaseBotUrl(): string
    {
        return $this->baseBotUrl;
    }

    /**
     * Set the Base Bot API URL.
     *
     * @param string $baseBotUrl
     *
     * @return $this
     */
    public function setBaseBotApiUrl(string $baseBotUrl): self
    {
        $this->baseBotUrl = $baseBotUrl;

        return $this;
    }

    /**
     * Check if this is an asynchronous request (non-blocking).
     *
     * @return bool
     */
    public function isAsyncRequest(): bool
    {
        return $this->isAsyncRequest;
    }

    /**
     * Make this request asynchronous (non-blocking).
     *
     * @param bool $isAsyncRequest
     *
     * @return $this
     */
    public function setAsyncRequest(bool $isAsyncRequest): self
    {
        $this->isAsyncRequest = $isAsyncRequest;

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeOut(): int
    {
        return $this->timeOut;
    }

    /**
     * @param int $timeOut
     *
     * @return $this
     */
    public function setTimeOut(int $timeOut): self
    {
        $this->timeOut = $timeOut;

        return $this;
    }

    /**
     * @return int
     */
    public function getConnectTimeOut(): int
    {
        return $this->connectTimeOut;
    }

    /**
     * @param int $connectTimeOut
     *
     * @return $this
     */
    public function setConnectTimeOut(int $connectTimeOut): self
    {
        $this->connectTimeOut = $connectTimeOut;

        return $this;
    }

    /**
     * Returns the last response returned from API request.
     *
     * @return TelegramResponse|null
     */
    public function getLastResponse(): ?TelegramResponse
    {
        return $this->lastResponse;
    }

    /**
     * Sends a GET request to Telegram Bot API and returns the result.
     *
     * @param string $endpoint
     * @param array  $params
     *
     * @throws TelegramSDKException
     *
     * @return TelegramResponse
     */
    public function get(string $endpoint, array $params = []): TelegramResponse
    {
        $params = $this->replyMarkupToString($params);

        return $this->sendRequest('GET', $endpoint, $params);
    }

    /**
     * Sends a POST request to Telegram Bot API and returns the result.
     *
     * @param string $endpoint
     * @param array  $params
     * @param bool   $fileUpload Set true if a file is being uploaded.
     *
     * @throws TelegramSDKException
     *
     * @return TelegramResponse
     */
    public function post(string $endpoint, array $params = [], bool $fileUpload = false): TelegramResponse
    {
        $params = $this->normalizeParams($params, $fileUpload);

        return $this->sendRequest('POST', $endpoint, $params);
    }

    /**
     * Sends a multipart/form-data request to Telegram Bot API and returns the result.
     * Used primarily for file uploads.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $inputFileField
     *
     * @throws TelegramSDKException
     * @throws CouldNotUploadInputFile
     *
     * @return TelegramResponse
     */
    public function uploadFile(string $endpoint, array $params, string $inputFileField): TelegramResponse
    {
        //Check if the field in the $params array (that is being used to send the relative file), is a file id.
        if (!isset($params[$inputFileField])) {
            throw CouldNotUploadInputFile::missingParam($inputFileField);
        }

        if (Validator::hasFileId($inputFileField, $params)) {
            return $this->post($endpoint, $params);
        }

        // Sending an actual file requires it to be sent using multipart/form-data
        return $this->post($endpoint, $this->prepareMultipartParams($params, $inputFileField), true);
    }

    /**
     * Converts a reply_markup field in the $params to a string.
     *
     * @param array $params
     *
     * @return array
     */
    protected function replyMarkupToString(array $params): array
    {
        if (isset($params['reply_markup'])) {
            $params['reply_markup'] = (string)$params['reply_markup'];
        }

        return $params;
    }

    /**
     * Prepare Multipart Params for File Upload.
     *
     * @param array  $params
     * @param string $inputFileField
     *
     * @throws CouldNotUploadInputFile
     *
     * @return array
     */
    protected function prepareMultipartParams(array $params, string $inputFileField): array
    {
        $this->validateInputFileField($params, $inputFileField);

        // Iterate through all param options and convert to multipart/form-data.
        return collect($params)
            ->reject(fn ($value) => null === $value)
            ->map(fn ($contents, $name) => $this->generateMultipartData($contents, $name))
            ->values()
            ->all();
    }

    /**
     * Generates the multipart data required when sending files to telegram.
     *
     * @param mixed  $contents
     * @param string $name
     *
     * @return array
     */
    protected function generateMultipartData($contents, string $name): array
    {
        if (!Validator::isInputFile($contents)) {
            return compact('name', 'contents');
        }

        return [
            'name'     => $name,
            'contents' => $contents->getContents(),
            'filename' => $contents->getFilename(),
        ];
    }

    /**
     * @param array  $params
     * @param string $inputFileField
     *
     * @throws CouldNotUploadInputFile
     */
    protected function validateInputFileField(array $params, string $inputFileField): void
    {
        if (!isset($params[$inputFileField])) {
            throw CouldNotUploadInputFile::missingParam($inputFileField);
        }

        // All file-paths, urls, or file resources should be provided by using the InputFile object
        if (
            (!$params[$inputFileField] instanceof InputFile) ||
            (is_string($params[$inputFileField]) && !Validator::isJson($params[$inputFileField]))
        ) {
            throw CouldNotUploadInputFile::inputFileParameterShouldBeInputFileEntity($inputFileField);
        }
    }

    /**
     * @param array $params
     * @param bool  $fileUpload
     *
     * @return array
     */
    protected function normalizeParams(array $params, bool $fileUpload = false): array
    {
        if ($fileUpload) {
            return ['multipart' => $params];
        }

        return ['form_params' => $this->replyMarkupToString($params)];
    }

    /**
     * Instantiates a new TelegramRequest entity.
     *
     * @param string $method
     * @param string $endpoint
     * @param array  $params
     *
     * @throws TelegramSDKException
     * @return TelegramRequest
     */
    protected function resolveTelegramRequest(string $method, string $endpoint, array $params = []): TelegramRequest
    {
        return (new TelegramRequest(
            $this->getAccessToken(),
            $method,
            $endpoint,
            $params,
            $this->isAsyncRequest()
        ))
            ->setTimeOut($this->getTimeOut())
            ->setConnectTimeOut($this->getConnectTimeOut());
    }

    /**
     * Sends a request to Telegram Bot API and returns the result.
     *
     * @param string $method
     * @param string $endpoint
     * @param array  $params
     *
     * @throws TelegramSDKException
     *
     * @return TelegramResponse
     */
    protected function sendRequest(string $method, string $endpoint, array $params = []): TelegramResponse
    {
        $request = $this->resolveTelegramRequest($method, $endpoint, $params);

        $rawResponse = $this->getHttpClientHandler()
            ->setTimeOut($request->getTimeOut())
            ->setConnectTimeOut($request->getConnectTimeOut())
            ->send(
                $this->makeApiUrl($request),
                $request->getMethod(),
                $request->getHeaders(),
                $this->getOption($request, $method),
                $request->isAsyncRequest(),
            );

        $returnResponse = $this->getResponse($request, $rawResponse);

        if ($returnResponse->isError()) {
            throw $returnResponse->getThrownException();
        }

        return $this->lastResponse = $returnResponse;
    }

    /**
     * Make API URL.
     *
     * @param TelegramRequest $request
     *
     * @throws TelegramSDKException
     * @return string
     */
    protected function makeApiUrl(TelegramRequest $request): string
    {
        return $this->getBaseBotUrl() . $request->getAccessToken() . '/' . $request->getEndpoint();
    }

    /**
     * Creates response object.
     *
     * @param TelegramRequest                    $request
     * @param ResponseInterface|PromiseInterface $response
     *
     * @return TelegramResponse
     */
    protected function getResponse(TelegramRequest $request, $response): TelegramResponse
    {
        return new TelegramResponse($request, $response);
    }

    /**
     * @param TelegramRequest $request
     * @param string          $method
     *
     * @return array
     */
    protected function getOption(TelegramRequest $request, string $method): array
    {
        if ($method === 'POST') {
            return $request->getPostParams();
        }

        return ['query' => $request->getParams()];
    }
}