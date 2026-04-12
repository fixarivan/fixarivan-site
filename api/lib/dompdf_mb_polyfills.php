<?php
declare(strict_types=1);

/**
 * Dompdf и зависимости (FontLib, Svg) вызывают mb_* без ведущего «\» из своего namespace.
 * Без расширения mbstring PHP ищет, например, Dompdf\mb_list_encodings() — её и подставляем.
 * При включённом mbstring делегируем в глобальные функции.
 */

namespace Dompdf {
    if (!function_exists('Dompdf\\mb_stripos')) {
        function mb_stripos($haystack, $needle, $offset = 0, $encoding = null)
        {
            if (\function_exists('mb_stripos')) {
                return \mb_stripos($haystack, $needle, (int)$offset, $encoding ?: 'UTF-8');
            }
            return \stripos((string)$haystack, (string)$needle, (int)$offset);
        }
    }
    if (!function_exists('Dompdf\\mb_strtolower')) {
        function mb_strtolower($string, $encoding = null)
        {
            if (\function_exists('mb_strtolower')) {
                return \mb_strtolower((string)$string, $encoding ?: 'UTF-8');
            }
            return \strtolower((string)$string);
        }
    }
    if (!function_exists('Dompdf\\mb_substr')) {
        function mb_substr($string, $start, $length = null, $encoding = null)
        {
            if (\function_exists('mb_substr')) {
                return \mb_substr((string)$string, (int)$start, $length, $encoding ?: 'UTF-8');
            }
            return $length === null
                ? \substr((string)$string, (int)$start)
                : \substr((string)$string, (int)$start, (int)$length);
        }
    }
    if (!function_exists('Dompdf\\mb_convert_encoding')) {
        function mb_convert_encoding($string, $to_encoding, $from_encoding = null)
        {
            if (\function_exists('mb_convert_encoding')) {
                return \mb_convert_encoding((string)$string, (string)$to_encoding, $from_encoding ?? 'UTF-8');
            }
            return (string)$string;
        }
    }
    if (!function_exists('Dompdf\\mb_internal_encoding')) {
        function mb_internal_encoding($encoding = null)
        {
            return \function_exists('mb_internal_encoding') ? \mb_internal_encoding($encoding) : 'UTF-8';
        }
    }
    if (!function_exists('Dompdf\\mb_strlen')) {
        function mb_strlen($str, $encoding = null)
        {
            return \function_exists('mb_strlen')
                ? \mb_strlen((string) $str, $encoding ?: 'UTF-8')
                : \strlen((string) $str);
        }
    }
    if (!function_exists('Dompdf\\mb_strwidth')) {
        function mb_strwidth($str, $encoding = null)
        {
            return \function_exists('mb_strwidth')
                ? \mb_strwidth((string) $str, $encoding ?: 'UTF-8')
                : \strlen((string) $str);
        }
    }
    if (!function_exists('Dompdf\\mb_list_encodings')) {
        function mb_list_encodings(): array
        {
            return \function_exists('mb_list_encodings')
                ? \mb_list_encodings()
                : ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII', '8bit'];
        }
    }
}

namespace FontLib\TrueType {
    if (!function_exists('FontLib\\TrueType\\mb_substr')) {
        function mb_substr($string, $start, $length = null, $encoding = null)
        {
            if (\function_exists('mb_substr')) {
                return \mb_substr((string)$string, (int)$start, $length, $encoding ?: 'UTF-8');
            }
            return $length === null
                ? \substr((string)$string, (int)$start)
                : \substr((string)$string, (int)$start, (int)$length);
        }
    }
    if (!function_exists('FontLib\\TrueType\\mb_strlen')) {
        function mb_strlen($str, $encoding = null)
        {
            return \function_exists('mb_strlen')
                ? \mb_strlen((string)$str, $encoding ?: 'UTF-8')
                : \strlen((string)$str);
        }
    }
    if (!function_exists('FontLib\\TrueType\\mb_list_encodings')) {
        function mb_list_encodings(): array
        {
            return \function_exists('mb_list_encodings')
                ? \mb_list_encodings()
                : ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII', '8bit'];
        }
    }
}

namespace FontLib\Table\Type {
    if (!function_exists('FontLib\\Table\\Type\\mb_convert_encoding')) {
        function mb_convert_encoding($string, $to_encoding, $from_encoding = null)
        {
            if (\function_exists('mb_convert_encoding')) {
                return \mb_convert_encoding((string)$string, (string)$to_encoding, $from_encoding ?? 'UTF-8');
            }
            return (string)$string;
        }
    }
    if (!function_exists('FontLib\\Table\\Type\\mb_list_encodings')) {
        function mb_list_encodings(): array
        {
            return \function_exists('mb_list_encodings')
                ? \mb_list_encodings()
                : ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII', '8bit'];
        }
    }
}

namespace Svg\Surface {
    if (!function_exists('Svg\\Surface\\mb_strtolower')) {
        function mb_strtolower($string, $encoding = null)
        {
            if (\function_exists('mb_strtolower')) {
                return \mb_strtolower((string)$string, $encoding ?: 'UTF-8');
            }
            return \strtolower((string)$string);
        }
    }
    if (!function_exists('Svg\\Surface\\mb_list_encodings')) {
        function mb_list_encodings(): array
        {
            return \function_exists('mb_list_encodings')
                ? \mb_list_encodings()
                : ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII', '8bit'];
        }
    }
}
