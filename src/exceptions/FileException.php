<?php

namespace FeedonomicsWebHookSDK\exceptions;
use Exception;
class FileException extends Exception
{
    const OPEN_ERROR =1;
    const SEEK_ERROR =2;
}