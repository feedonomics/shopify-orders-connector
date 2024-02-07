<?php

namespace ShopifyOrdersConnector\services;

use DateTime;
use DateTimeZone;
use Exception;

class ConversionUtils
{
    const ISO2_TO_ISO3 = [
        "BD" => "BGD", "BE" => "BEL", "BF" => "BFA", "BG" => "BGR", "BA" => "BIH", "BB" => "BRB", "WF" => "WLF",
        "BL" => "BLM", "BM" => "BMU", "BN" => "BRN", "BO" => "BOL", "BH" => "BHR", "BI" => "BDI", "BJ" => "BEN",
        "BT" => "BTN", "JM" => "JAM", "BV" => "BVT", "BW" => "BWA", "WS" => "WSM", "BQ" => "BES", "BR" => "BRA",
        "BS" => "BHS", "JE" => "JEY", "BY" => "BLR", "BZ" => "BLZ", "RU" => "RUS", "RW" => "RWA", "RS" => "SRB",
        "TL" => "TLS", "RE" => "REU", "TM" => "TKM", "TJ" => "TJK", "RO" => "ROU", "TK" => "TKL", "GW" => "GNB",
        "GU" => "GUM", "GT" => "GTM", "GS" => "SGS", "GR" => "GRC", "GQ" => "GNQ", "GP" => "GLP", "JP" => "JPN",
        "GY" => "GUY", "GG" => "GGY", "GF" => "GUF", "GE" => "GEO", "GD" => "GRD", "GB" => "GBR", "GA" => "GAB",
        "SV" => "SLV", "GN" => "GIN", "GM" => "GMB", "GL" => "GRL", "GI" => "GIB", "GH" => "GHA", "OM" => "OMN",
        "TN" => "TUN", "JO" => "JOR", "HR" => "HRV", "HT" => "HTI", "HU" => "HUN", "HK" => "HKG", "HN" => "HND",
        "HM" => "HMD", "VE" => "VEN", "PR" => "PRI", "PS" => "PSE", "PW" => "PLW", "PT" => "PRT", "SJ" => "SJM",
        "PY" => "PRY", "IQ" => "IRQ", "PA" => "PAN", "PF" => "PYF", "PG" => "PNG", "PE" => "PER", "PK" => "PAK",
        "PH" => "PHL", "PN" => "PCN", "PL" => "POL", "PM" => "SPM", "ZM" => "ZMB", "EH" => "ESH", "EE" => "EST",
        "EG" => "EGY", "ZA" => "ZAF", "EC" => "ECU", "IT" => "ITA", "VN" => "VNM", "SB" => "SLB", "ET" => "ETH",
        "SO" => "SOM", "ZW" => "ZWE", "SA" => "SAU", "ES" => "ESP", "ER" => "ERI", "ME" => "MNE", "MD" => "MDA",
        "MG" => "MDG", "MF" => "MAF", "MA" => "MAR", "MC" => "MCO", "UZ" => "UZB", "MM" => "MMR", "ML" => "MLI",
        "MO" => "MAC", "MN" => "MNG", "MH" => "MHL", "MK" => "MKD", "MU" => "MUS", "MT" => "MLT", "MW" => "MWI",
        "MV" => "MDV", "MQ" => "MTQ", "MP" => "MNP", "MS" => "MSR", "MR" => "MRT", "IM" => "IMN", "UG" => "UGA",
        "TZ" => "TZA", "MY" => "MYS", "MX" => "MEX", "IL" => "ISR", "FR" => "FRA", "IO" => "IOT", "SH" => "SHN",
        "FI" => "FIN", "FJ" => "FJI", "FK" => "FLK", "FM" => "FSM", "FO" => "FRO", "NI" => "NIC", "NL" => "NLD",
        "NO" => "NOR", "NA" => "NAM", "VU" => "VUT", "NC" => "NCL", "NE" => "NER", "NF" => "NFK", "NG" => "NGA",
        "NZ" => "NZL", "NP" => "NPL", "NR" => "NRU", "NU" => "NIU", "CK" => "COK", "XK" => "XKX", "CI" => "CIV",
        "CH" => "CHE", "CO" => "COL", "CN" => "CHN", "CM" => "CMR", "CL" => "CHL", "CC" => "CCK", "CA" => "CAN",
        "CG" => "COG", "CF" => "CAF", "CD" => "COD", "CZ" => "CZE", "CY" => "CYP", "CX" => "CXR", "CR" => "CRI",
        "CW" => "CUW", "CV" => "CPV", "CU" => "CUB", "SZ" => "SWZ", "SY" => "SYR", "SX" => "SXM", "KG" => "KGZ",
        "KE" => "KEN", "SS" => "SSD", "SR" => "SUR", "KI" => "KIR", "KH" => "KHM", "KN" => "KNA", "KM" => "COM",
        "ST" => "STP", "SK" => "SVK", "KR" => "KOR", "SI" => "SVN", "KP" => "PRK", "KW" => "KWT", "SN" => "SEN",
        "SM" => "SMR", "SL" => "SLE", "SC" => "SYC", "KZ" => "KAZ", "KY" => "CYM", "SG" => "SGP", "SE" => "SWE",
        "SD" => "SDN", "DO" => "DOM", "DM" => "DMA", "DJ" => "DJI", "DK" => "DNK", "VG" => "VGB", "DE" => "DEU",
        "YE" => "YEM", "DZ" => "DZA", "US" => "USA", "UY" => "URY", "YT" => "MYT", "UM" => "UMI", "LB" => "LBN",
        "LC" => "LCA", "LA" => "LAO", "TV" => "TUV", "TW" => "TWN", "TT" => "TTO", "TR" => "TUR", "LK" => "LKA",
        "LI" => "LIE", "LV" => "LVA", "TO" => "TON", "LT" => "LTU", "LU" => "LUX", "LR" => "LBR", "LS" => "LSO",
        "TH" => "THA", "TF" => "ATF", "TG" => "TGO", "TD" => "TCD", "TC" => "TCA", "LY" => "LBY", "VA" => "VAT",
        "VC" => "VCT", "AE" => "ARE", "AD" => "AND", "AG" => "ATG", "AF" => "AFG", "AI" => "AIA", "VI" => "VIR",
        "IS" => "ISL", "IR" => "IRN", "AM" => "ARM", "AL" => "ALB", "AO" => "AGO", "AQ" => "ATA", "AS" => "ASM",
        "AR" => "ARG", "AU" => "AUS", "AT" => "AUT", "AW" => "ABW", "IN" => "IND", "AX" => "ALA", "AZ" => "AZE",
        "IE" => "IRL", "ID" => "IDN", "UA" => "UKR", "QA" => "QAT", "MZ" => "MOZ"
    ];

    /**
     * @param $state_code
     * @return string
     */
    static public function convert_usa_state_to_2_chars($state_code)
    {
        $state_code_working = $state_code;
        $state_code_working = preg_replace('/[^a-z]/i', '', $state_code_working);
        $state_code_working = strtolower($state_code_working);

        $map = array(
            "alabama" => "AL",
            "alaska" => "AK",
            "arizona" => "AZ",
            "arkansas" => "AR",
            "california" => "CA",
            "colorado" => "CO",
            "connecticut" => "CT",
            "delaware" => "DE",
            "districtofcolumbia" => "DC",
            "florida" => "FL",
            "georgia" => "GA",
            "hawaii" => "HI",
            "idaho" => "ID",
            "illinois" => "IL",
            "indiana" => "IN",
            "iowa" => "IA",
            "kansas" => "KS",
            "kentucky" => "KY",
            "louisiana" => "LA",
            "maine" => "ME",
            "maryland" => "MD",
            "massachusetts" => "MA",
            "michigan" => "MI",
            "minnesota" => "MN",
            "mississippi" => "MS",
            "missouri" => "MO",
            "montana" => "MT",
            "nebraska" => "NE",
            "nevada" => "NV",
            "newhampshire" => "NH",
            "newjersey" => "NJ",
            "newmexico" => "NM",
            "newyork" => "NY",
            "northcarolina" => "NC",
            "northdakota" => "ND",
            "ohio" => "OH",
            "oklahoma" => "OK",
            "oregon" => "OR",
            "pennsylvania" => "PA",
            "rhodeisland" => "RI",
            "southcarolina" => "SC",
            "southdakota" => "SD",
            "tennessee" => "TN",
            "texas" => "TX",
            "utah" => "UT",
            "vermont" => "VT",
            "virginia" => "VA",
            "washington" => "WA",
            "westvirginia" => "WV",
            "wisconsin" => "WI",
            "wyoming" => "WY",
            "puertorico" => "PR"
        );

        // output defaults to char-filtered capitalized string
        $output = strtoupper($state_code_working);

        // if match found, use the two-character value
        if (array_key_exists($state_code_working, $map)) {
            $output = $map[$state_code_working];
        }

        return $output;
    }

    /**
     * @param $country_code
     * @return int|mixed|string
     */
    public static function convert_country_code_to_ISO2($country_code)
    {
        $working_country_code = strtoupper($country_code);

        $iso2_to_iso3 = self::ISO2_TO_ISO3;

        $iso3_to_iso2 = array_flip($iso2_to_iso3);

        if (is_array($iso3_to_iso2) && array_key_exists($working_country_code, $iso3_to_iso2)) {
            return $iso3_to_iso2[$working_country_code];
        }

        return $working_country_code;
    }

    /**
     * @param string $date
     * @param $timezone
     * @return string
     */
    public static function convert_date_to_utc_iso_8601(?string $date, $timezone = 'UTC')
    {
        if (!$date) {
            return '';
        }

        try {
            $dateTime = new DateTime($date, new DateTimeZone($timezone));
        } catch (Exception $e) {
            // TODO Add exception handling
            return '';
        }

        $dateTime->setTimezone(new DateTimeZone('UTC'));
        $formatted_date = $dateTime->format('c');
        return $formatted_date ?? '';
    }

    /**
     *    Convert country code from 2 to ISO3.
     *
     * Returns the ISO3 version of a ISO2 code.
     * If the supplied code is already ISO3 or a mapping is not found,
     * the original code will be returned.
     */
    public static function convert_country_code_to_ISO3($country_code)
    {
        $working_country_code = strtoupper($country_code);

        $iso2_to_iso3 = self::ISO2_TO_ISO3;

        if (is_array($iso2_to_iso3) && array_key_exists($working_country_code, $iso2_to_iso3)) {
            return $iso2_to_iso3[$working_country_code];
        }

        return $working_country_code;
    }

}