<?php

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log; // Import the Log facade
use App\Traits\ResponseTrait; // Use the ResponseTrait

class Handler extends ExceptionHandler
{
    use ResponseTrait; // Include the response trait

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Log the exception details with its code
            Log::error($e->getMessage(), ['exception' => $e, 'code' => $e->getCode()]);
        });
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return $this->error('Unauthorized access. Please log in.', 401);
    }

    public function render($request, Throwable $exception) // Change Exception to Throwable
    {
        // Default response for unexpected exceptions
        $response = [
            'success' => false,
            'message' => 'An error occurred. Please try again later.',
            'code' => 500 // Default error code
        ];

        // Check for specific exceptions and set the appropriate response
        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Resource not found.', 404);
        }

        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->error('The requested URL was not found on this server.', 404);
        }

        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return $this->unauthenticated($request, $exception);
        }

        // Handle other exceptions
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return $this->error($exception->getMessage(), $exception->getStatusCode());
        }

        // If it's a generic server error, respond with 500
        return $this->error($response['message'], 500);
    }
}
