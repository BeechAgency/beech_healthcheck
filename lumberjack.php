<?php
/**
 * Beech_Lumberack: Enhanced logging class for WordPress
 */

class Beech_Lumberack {

    protected $log_file;

    public function __construct( $file_name = 'beech-lumberack-log.txt' ) {
        $upload_dir = wp_upload_dir();
        $this->log_file = trailingslashit( $upload_dir['basedir'] ) . sanitize_file_name( $file_name );

        if ( ! is_writable( dirname( $this->log_file ) ) ) {
            error_log( 'Beech_Lumberack error: Log directory is not writable.' );
        }
    }

    /**
     * Write a log entry with request context.
     *
     * @param string $message
     * @param string $level Optional. INFO, DEBUG, ERROR, etc.
     */
    public function log( $message, $level = 'INFO' ) {
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $context = $this->get_request_context();
        $entry = "[{$timestamp}] [{$level}] {$message} {$context}" . PHP_EOL;

        if ( is_writable( dirname( $this->log_file ) ) ) {
            file_put_contents( $this->log_file, $entry, FILE_APPEND | LOCK_EX );
        } else {
            error_log( 'Beech_Lumberack error: Failed to write to log file.' );
        }
    }

    /**
     * Capture and log a PHP Exception or Error.
     *
     * @param \Throwable $e
     */
    public function log_exception( $e ) {
        $message = sprintf(
            'Exception: %s in %s on line %d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        $this->log( $message, 'ERROR' );
    }

    /**
     * Get request context (IP, method, URI).
     *
     * @return string
     */
    protected function get_request_context() {
        $ip     = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'NONE';
        $uri    = $_SERVER['REQUEST_URI'] ?? 'NO_URI';

        return "[{$ip}] {$method} {$uri}";
    }

    /**
     * Retrieve the current log file contents.
     *
     * @return string|false
     */
    public function get_log() {
        if ( file_exists( $this->log_file ) ) {
            return file_get_contents( $this->log_file );
        }
        return false;
    }

    /**
     * Clear the log.
     */
    public function clear_log() {
        if ( file_exists( $this->log_file ) && is_writable( $this->log_file ) ) {
            file_put_contents( $this->log_file, '' );
        }
    }

    /**
     * Static helper for simple logging.
     *
     * @param string $message
     * @param string $level
     */
    public static function quick_log( $message, $level = 'INFO' ) {
        $logger = new self();
        $logger->log( $message, $level );
    }
}