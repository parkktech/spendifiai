<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Upload = 'upload';
    case Classifying = 'classifying';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Splitting = 'splitting';
    case Split = 'split';
}
