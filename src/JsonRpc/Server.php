<?php

namespace JsonRpc\JsonRpc;

use Dotenv\Dotenv;
use JsonRpc\JsonRpc\Exceptions\Exception;
use JsonRpc\JsonRpc\Responses\ResponseError;

/**
 * @link http://www.jsonrpc.org/specification JSON-RPC 2.0 Specifications
 */
class Server
{
    /** @var string */
    const string VERSION = '2.0';

    /** @var Core */
    private Core $core;

    /**
     * @param Core $core
     */
    public function __construct(Core $core)
    {
        $this->core = $core;

        $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
        $dotenv->load();
    }

    /**
     * Processes a JSON-RPC 2.0 client request string and prepares a valid
     * response.
     *
     * @param  string  $json
     * Single request object, or an array of request objects, as a JSON string.
     *
     * @return null|string
     * Returns null when no response is necessary.
     * Returns a response/error object, as a JSON string, when a query is made.
     * Returns an array of response/error objects, as a JSON string, when multiple queries are made.
     * @see Responses\ResponseResult
     * @see Responses\ResponseError
     */
    public function handle(mixed $json): ?string
    {
        if (is_string($json)) {
            $input = json_decode($json, true);
        } else {
            $input = null;
        }

        $response = $this->rawHandle($input);

        if (is_array($response)) {
            $output = json_encode($response);
        } else {
            $output = null;
        }

        return $output;
    }

    /**
     * Processes a JSON-RPC 2.0 client request array and prepares a valid
     * response. This method skips the JSON encoding and decoding steps,
     * so you can use your own alternative encoding algorithm, or extend
     * the JSON-RPC 2.0 format.
     *
     * When you use this method, you are taking responsibility for
     * performing the necessary JSON encoding and decoding steps yourself.
     * @see self::handle()
     *
     * @param mixed $input
     * An array containing the JSON-decoded client request.
     *
     * @return null|array
     * Returns null if no response is necessary
     * Returns the JSON-RPC 2.0 server response as an array
     */
    public function rawHandle(mixed $input): ?array
    {
        if (is_array($input)) {
            $response = $this->processInput($input);
        } else {
            $response = $this->parseError();
        }

        return $response;
    }

    /**
     * Processes the user input, and prepares a response (if necessary).
     *
     * @param array $input
     * Single request object, or an array of request objects.
     *
     * @return array|null
     * Returns a response object (or an error object) when a query is made.
     * Returns an array of response/error objects when multiple queries are made.
     * Returns null when no response is necessary.
     */
    private function processInput(array $input): ?array
    {
        if (count($input) === 0) {
            return $this->requestError();
        }

        if (isset($input[0])) {
            return $this->processBatchRequests($input);
        }

        return $this->processRequest($input);
    }

    /**
     * Processes a batch of user requests, and prepares the response.
     *
     * @param array $input
     * Array of request objects.
     *
     * @return array|null
     * Returns a response/error object when a query is made.
     * Returns an array of response/error objects when multiple queries are made.
     * Returns null when no response is necessary.
     */
    private function processBatchRequests(mixed $input): ?array
    {
        $replies = array();

        foreach ($input as $request) {
            $response = $this->processRequest($request);

            if ($response !== null) {
                $replies[] = $response;
            }
        }

        if (count($replies) === 0) {
            return null;
        }

        return $replies;
    }

    /**
     * Processes an individual request, and prepares the response.
     *
     * @param array $request
     * Single request object to be processed.
     *
     * @return array|null
     * Returns a response object or an error object.
     * Returns null when no response is necessary.
     */
    private function processRequest(mixed $request): ?array
    {
        if (!is_array($request)) {
            return $this->requestError();
        }

        // The presence of the 'id' key indicates that a response is expected
        $isQuery = array_key_exists('id', $request);

        $id = &$request['id'];

        if (($id !== null) && !is_int($id) && !is_float($id) && !is_string($id)) {
            return $this->requestError();
        }

        $version = &$request['jsonrpc'];

        if ($version !== self::VERSION) {
            return $this->requestError($id);
        }

        $method = &$request['method'];

        if (!is_string($method)) {
            return $this->requestError($id);
        }

        // The 'params' key is optional, but must be non-null when provided
        if (array_key_exists('params', $request)) {
            $arguments = $request['params'];

            if (!is_array($arguments)) {
                return $this->requestError($id);
            }
        } else {
            $arguments = array();
        }

        if ($isQuery) {
            return $this->processQuery($id, $method, $arguments);
        }

        $this->processNotification($method, $arguments);
        return null;
    }

    /**
     * Processes a query request and prepares the response.
     *
     * @param mixed $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @param  string  $method
     * String value representing a method to invoke on the server.
     *
     * @param  array  $arguments
     * Array of arguments that will be passed to the method.
     *
     * @return array
     * Returns a response object or an error object.
     */
    private function processQuery(mixed $id, string $method, array $arguments): array
    {
        try {
            $result = $this->core->execute($method, $arguments);
            return $this->response($id, $result);
        } catch (Exception $exception) {
            $code = $exception->getCode();
            $message = $exception->getMessage();
            $data = $exception->getData();

            return $this->error($id, $code, $message, $data);
        }
    }

    /**
     * Processes a notification. No response is necessary.
     *
     * @param  string  $method
     * String value representing a method to invoke on the server.
     *
     * @param  array  $arguments
     * Array of arguments that will be passed to the method.
     */
    private function processNotification(string $method, array $arguments): void
    {
        try {
            $this->core->execute($method, $arguments);
        } catch (Exception $exception) {
        }
    }

    /**
     * Returns an error object explaining that an error occurred while parsing
     * the JSON text input.
     *
     * @return array
     * Returns an error object.
     */
    private function parseError(): array
    {
        return $this->error(null, ResponseError::PARSE_ERROR, 'Parse error');
    }

    /**
     * Returns an error object explaining that the JSON input is not a valid
     * request object.
     *
     * @param  mixed|null  $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @return array
     * Returns an error object.
     */
    private function requestError(mixed $id = null): array
    {
        return $this->error($id, ResponseError::INVALID_REQUEST, 'Invalid Request');
    }

    /**
     * Returns a properly-formatted error object.
     *
     * @param mixed $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @param  int  $code
     * Integer value representing the general type of error encountered.
     *
     * @param  string  $message
     * Concise description of the error (ideally a single sentence).
     *
     * @param null|boolean|integer|float|string|array $data
     * An optional primitive value that contains additional information about
     * the error.
     *
     * @return array
     * Returns an error object.
     */
    private function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = array(
            'code' => $code,
            'message' => $message
        );

        if ($data !== null) {
            $error['data'] = $data;
        }

        return array(
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => $error
        );
    }

    /**
     * Returns a properly-formatted response object.
     *
     * @param mixed $id
     * Client-supplied value that allows the client to associate the server response
     * with the original query.
     *
     * @param mixed $result
     * Return value from the server method, which will now be delivered to the user.
     *
     * @return array
     * Returns a response object.
     */
    private function response(mixed $id, mixed $result): array
    {
        return array(
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'result' => $result
        );
    }
}
