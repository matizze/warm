<?php

namespace App\Enums;

enum SearchMode: string
{
    case Lexical = 'lexical';
    case Semantic = 'semantic';
    case Hybrid = 'hybrid';
}
