<?php

namespace ShopifyOrdersConnector\services;

use feedonomics\clientlibrary\Resource;
use ShopifyOrdersConnector\exceptions\FileException;
use ShopifyOrdersConnector\exceptions\FtpException;

class FTPUtilities
{
    const CURL_ERROR_MESSAGE_MAP = [
        CURLE_UNSUPPORTED_PROTOCOL => "Unsupported protocol",
        CURLE_FAILED_INIT => "Could not connect",
        CURLE_URL_MALFORMAT => "URL using bad/illegal format or missing URL",
        CURLE_URL_MALFORMAT_USER => "A requested curl feature, protocol or option was not found",
        CURLE_COULDNT_RESOLVE_PROXY => "Couldn't resolve proxy name",
        CURLE_COULDNT_RESOLVE_HOST => "Couldn't resolve host name",
        CURLE_FTP_WEIRD_SERVER_REPLY => "FTP: weird reply",
        CURLE_FTP_ACCESS_DENIED => "Access denied to resource ",
        CURLE_FTP_USER_PASSWORD_INCORRECT => "FTP: failed to connect to data port",
        CURLE_FTP_WEIRD_PASS_REPLY => "FTP: unknown PASS reply",
        CURLE_FTP_WEIRD_USER_REPLY => "FTP: connection has timed out",
        CURLE_FTP_WEIRD_PASV_REPLY => "FTP: unknown PASV reply",
        CURLE_FTP_WEIRD_227_FORMAT => "FTP: unknown 227 response format",
        CURLE_FTP_CANT_GET_HOST => "FTP: can't figure out the host in the PASV response",
        CURLE_FTP_COULDNT_SET_BINARY => "FTP: couldn't set file type",
        CURLE_PARTIAL_FILE => "Unexpectedly closed by the remote server",
        CURLE_FTP_COULDNT_RETR_FILE => "FTP: couldn't retrieve the specified file",
        CURLE_FTP_QUOTE_ERROR => "Quote command returned error",
        CURLE_HTTP_NOT_FOUND => "HTTP response indicated an error",
        CURLE_WRITE_ERROR => "Failed writing received data to disk/application",
        CURLE_FTP_COULDNT_STOR_FILE => "Upload failed (at start/before it took off)",
        CURLE_READ_ERROR => "Failed to open/read local data from file/application",
        CURLE_OUT_OF_MEMORY => "Out of memory",
        CURLE_OPERATION_TIMEOUTED => "Timed out",
        CURLE_FTP_PORT_FAILED => "FTP: command PORT failed",
        CURLE_FTP_COULDNT_USE_REST => "FTP: command REST failed",
        CURLE_HTTP_RANGE_ERROR => "Requested range was not delivered",
        CURLE_HTTP_POST_ERROR => "Internal problem setting up the POST",
        CURLE_SSL_CONNECT_ERROR => "SSL connect error",
        CURLE_FTP_BAD_DOWNLOAD_RESUME => "Couldn't resume download",
        CURLE_FILE_COULDNT_READ_FILE => "Couldn't read a file:// file",
        CURLE_LDAP_CANNOT_BIND => "LDAP: cannot bind",
        CURLE_LDAP_SEARCH_FAILED => "LDAP: search failed",
        CURLE_FUNCTION_NOT_FOUND => "A required function in the library was not found",
        CURLE_ABORTED_BY_CALLBACK => "Operation was aborted by an application callback",
        CURLE_BAD_FUNCTION_ARGUMENT => "A libcurl function was given a bad argument",
        CURLE_HTTP_PORT_FAILED => "Failed binding local connection end",
        CURLE_TOO_MANY_REDIRECTS => "Maximum number of redirects reached",
        CURLE_UNKNOWN_TELNET_OPTION => "An unknown option was passed in to libcurl",
        CURLE_TELNET_OPTION_SYNTAX => "Malformed telnet option",
        CURLE_SSL_PEER_CERTIFICATE => "SSL certificate or SSH remote key was not OK",
        CURLE_GOT_NOTHING => "Returned nothing (no headers, no data)",
        CURLE_SSL_ENGINE_NOTFOUND => "SSL crypto engine not found",
        CURLE_SSL_ENGINE_SETFAILED => "Can not set SSL crypto engine as default",
        CURLE_SEND_ERROR => "Failed sending data",
        CURLE_RECV_ERROR => "Unexpectedly reset by the remote server",
        CURLE_SHARE_IN_USE => "Failure when receiving data from the peer",
        CURLE_SSL_CERTPROBLEM => "Problem with the local SSL certificate",
        CURLE_SSL_CIPHER => "Couldn't use specified SSL cipher",
        CURLE_SSL_CACERT => "Ssl certificate cannot be authenticated with given CA certificates",
        CURLE_BAD_CONTENT_ENCODING => "Unrecognized or bad HTTP Content or Transfer-Encoding",
        CURLE_LDAP_INVALID_URL => "Invalid LDAP URL",
        CURLE_FILESIZE_EXCEEDED => "Maximum file size exceeded",
        CURLE_FTP_SSL_FAILED => "Requested SSL level failed",
        65 => "Send failed since rewinding of the data stream failed",
        66 => "Failed to initialise SSL crypto engine",
        67 => "Login denied",
        68 => "TFTP: File Not Found",
        69 => "TFTP: Access Violation",
        70 => "Disk full or allocation exceeded",
        71 => "TFTP: Illegal operation",
        72 => "TFTP: Unknown transfer ID",
        73 => "Remote file already exists",
        74 => "TFTP: No such user",
        75 => "Conversion failed",
        76 => "Caller must register CURLOPT_CONV_ callback options",
        77 => "Problem with the SSL CA cert (path? access rights?)",
        78 => "File transfer failed as either the remote file or directory does not exist",
        CURLE_SSH => "Error in the SSH layer",
        80 => "Failed to shut down the SSL connection",
        81 => "Socket not ready for send/recv",
        82 => "Failed to load CRL file (path? access rights?, format?)",
        83 => "Issuer check against certificate failed",
        84 => "FTP: did not accept the PRET command",
        85 => "RTSP CSeq mismatch or invalid CSeq",
        86 => "RTSP session error",
        87 => "Unable to parse the returned FTP file list",
        88 => "Chunk callback failed",
        89 => "Internal cURL error, no connection available",
        90 => 'Failed to match the pinned key specified with CURLOPT_PINNEDPUBLICKEY',
        91 => 'Status returned failure when asked with CURLOPT_SSL_VERIFYSTATUS',
        92 => 'Stream error in the HTTP/2 framing layer'
    ];

    private string $username;
    private string $password;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @param string $fq_url
     * @return string
     */
    public function get_file_name(string $fq_url)
    {
        $parts = parse_url($fq_url);
        $path = $parts['path'];
        return basename($path);
    }

    /**
     * @param string $fq_url
     * @return string
     */
    public function get_directory(string $fq_url)
    {
        $parts = parse_url($fq_url);
        $path = $parts['path'];
        return dirname($path);
    }

    /**
     * @param string $fq_url
     * @return string
     */
    public function get_ftp_server(string $fq_url)
    {
        $parts = parse_url($fq_url);
        $server = "{$parts['scheme']}://{$parts['host']}";
        if ($parts['port'] ?? '') {
            $server .= ":{$parts['port']}";
        }
        return $server;
    }

    /**
     * @param string $file
     * @return string
     */
    public function build_results_file_name(string $file)
    {
        $parts = pathinfo($file);
        return $parts['filename'] . "_results.csv";
    }

    /**
     * @param string $url
     * @return \resource
     * @throws FileException
     * @throws FtpException
     */
    public function download(string $url)
    {
        $temp_file = tmpfile();
        if (!$temp_file) {
            throw new FileException("Unable to open temp file", FileException::OPEN_ERROR);
        }
        $ch = curl_init();
        $curlopt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_FILE => $temp_file,
            CURLOPT_PROTOCOLS => CURLPROTO_SFTP,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => 1,
        ];
        curl_setopt_array($ch, $curlopt);
        curl_exec($ch);

        $curl_errorno = curl_errno($ch);
        if ($curl_errorno) {
            throw new FtpException(self::CURL_ERROR_MESSAGE_MAP[$curl_errorno], curl_errno($ch));
        }
        curl_close($ch);
        $status = fseek($temp_file, 0);

        if ($status == -1) {
            fclose($temp_file);
            throw new FileException("Unable to read from temp file", FileException::SEEK_ERROR);
        }

        return $temp_file;
    }

    /**
     * @param string $url
     * @param \resource $file_handle
     * @return void
     * @throws FileException
     * @throws FtpException
     */
    public function upload(string $url, $file_handle)
    {
        $status = fseek($file_handle, 0);
        if ($status == -1) {
            throw new FileException("Unable to read from file", FileException::SEEK_ERROR);
        }

        $curlopt_array = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $file_handle,
            CURLOPT_PROTOCOLS => CURLPROTO_SFTP,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => 1,
        ];
        $ch = curl_init();
        curl_setopt_array($ch, $curlopt_array);

        curl_exec($ch);

        if (curl_errno($ch)) {
            throw new FtpException(curl_error($ch), curl_errno($ch));
        }
    }
}