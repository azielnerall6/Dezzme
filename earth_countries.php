<?php
/* ================================================================
   earth_countries.php — turns the free-text "Current location" (About me) into a COUNTRY.
   Used only by earth.php. Country level only: nothing here ever returns a city or a person.
   Matching: every comma-separated part is checked from the last to the first against country
   names, common short names (USA, UK, UAE …) and a list of big cities.
   ================================================================ */

function earth_country_table() {
    static $T = null;
    if ($T !== null) return $T;
    $raw = <<<'T'
AF|Afghanistan|33.9|67.7
AL|Albania|41.2|20.2
DZ|Algeria|28.0|1.7
AD|Andorra|42.5|1.6
AO|Angola|-11.2|17.9
AG|Antigua and Barbuda|17.1|-61.8
AR|Argentina|-38.4|-63.6
AM|Armenia|40.1|45.0
AU|Australia|-25.3|133.8
AT|Austria|47.5|14.6
AZ|Azerbaijan|40.1|47.6
BS|Bahamas|25.0|-77.4
BH|Bahrain|26.0|50.6
BD|Bangladesh|23.7|90.4
BB|Barbados|13.2|-59.5
BY|Belarus|53.7|27.9
BE|Belgium|50.5|4.5
BZ|Belize|17.2|-88.5
BJ|Benin|9.3|2.3
BT|Bhutan|27.5|90.4
BO|Bolivia|-16.3|-63.6
BA|Bosnia and Herzegovina|43.9|17.7
BW|Botswana|-22.3|24.7
BR|Brazil|-14.2|-51.9
BN|Brunei|4.5|114.7
BG|Bulgaria|42.7|25.5
BF|Burkina Faso|12.2|-1.6
BI|Burundi|-3.4|29.9
KH|Cambodia|12.6|105.0
CM|Cameroon|7.4|12.4
CA|Canada|56.1|-106.3
CV|Cape Verde|16.0|-24.0
CF|Central African Republic|6.6|20.9
TD|Chad|15.5|18.7
CL|Chile|-35.7|-71.5
CN|China|35.9|104.2
CO|Colombia|4.6|-74.3
KM|Comoros|-11.9|43.9
CG|Congo|-0.2|15.8
CD|Democratic Republic of the Congo|-4.0|21.8
CR|Costa Rica|9.7|-83.8
CI|Ivory Coast|7.5|-5.5
HR|Croatia|45.1|15.2
CU|Cuba|21.5|-77.8
CY|Cyprus|35.1|33.4
CZ|Czech Republic|49.8|15.5
DK|Denmark|56.3|9.5
DJ|Djibouti|11.8|42.6
DM|Dominica|15.4|-61.4
DO|Dominican Republic|18.7|-70.2
EC|Ecuador|-1.8|-78.2
EG|Egypt|26.8|30.8
SV|El Salvador|13.8|-88.9
GQ|Equatorial Guinea|1.7|10.3
ER|Eritrea|15.2|39.8
EE|Estonia|58.6|25.0
SZ|Eswatini|-26.5|31.5
ET|Ethiopia|9.1|40.5
FJ|Fiji|-17.7|178.1
FI|Finland|61.9|25.7
FR|France|46.2|2.2
GA|Gabon|-0.8|11.6
GM|Gambia|13.4|-15.3
GE|Georgia|42.3|43.4
DE|Germany|51.2|10.5
GH|Ghana|7.9|-1.0
GR|Greece|39.1|21.8
GD|Grenada|12.1|-61.7
GT|Guatemala|15.8|-90.2
GN|Guinea|9.9|-9.7
GW|Guinea-Bissau|11.8|-15.2
GY|Guyana|4.9|-58.9
HT|Haiti|19.0|-72.3
HN|Honduras|15.2|-86.2
HU|Hungary|47.2|19.5
IS|Iceland|64.9|-19.0
IN|India|20.6|78.9
ID|Indonesia|-0.8|113.9
IR|Iran|32.4|53.7
IQ|Iraq|33.2|43.7
IE|Ireland|53.4|-8.2
IL|Israel|31.0|34.9
IT|Italy|41.9|12.6
JM|Jamaica|18.1|-77.3
JP|Japan|36.2|138.3
JO|Jordan|30.6|36.2
KZ|Kazakhstan|48.0|66.9
KE|Kenya|-0.0|37.9
KI|Kiribati|1.9|-157.4
XK|Kosovo|42.6|20.9
KW|Kuwait|29.3|47.5
KG|Kyrgyzstan|41.2|74.8
LA|Laos|19.9|102.5
LV|Latvia|56.9|24.6
LB|Lebanon|33.9|35.9
LS|Lesotho|-29.6|28.2
LR|Liberia|6.4|-9.4
LY|Libya|26.3|17.2
LI|Liechtenstein|47.2|9.6
LT|Lithuania|55.2|23.9
LU|Luxembourg|49.8|6.1
MG|Madagascar|-18.8|46.9
MW|Malawi|-13.3|34.3
MY|Malaysia|4.2|102.0
MV|Maldives|3.2|73.2
ML|Mali|17.6|-4.0
MT|Malta|35.9|14.4
MR|Mauritania|21.0|-10.9
MU|Mauritius|-20.3|57.6
MX|Mexico|23.6|-102.6
MD|Moldova|47.4|28.4
MC|Monaco|43.7|7.4
MN|Mongolia|46.9|103.8
ME|Montenegro|42.7|19.4
MA|Morocco|31.8|-7.1
MZ|Mozambique|-18.7|35.5
MM|Myanmar|21.9|95.96
NA|Namibia|-22.96|18.5
NP|Nepal|28.4|84.1
NL|Netherlands|52.1|5.3
NZ|New Zealand|-40.9|174.9
NI|Nicaragua|12.9|-85.2
NE|Niger|17.6|8.1
NG|Nigeria|9.1|8.7
KP|North Korea|40.3|127.5
MK|North Macedonia|41.6|21.7
NO|Norway|60.5|8.5
OM|Oman|21.5|55.9
PK|Pakistan|30.4|69.3
PS|Palestine|31.9|35.2
PA|Panama|8.5|-80.8
PG|Papua New Guinea|-6.3|143.96
PY|Paraguay|-23.4|-58.4
PE|Peru|-9.2|-75.0
PH|Philippines|12.9|121.8
PL|Poland|51.9|19.1
PT|Portugal|39.4|-8.2
QA|Qatar|25.4|51.2
RO|Romania|45.9|24.97
RU|Russia|61.5|105.3
RW|Rwanda|-1.9|29.9
KN|Saint Kitts and Nevis|17.4|-62.8
LC|Saint Lucia|13.9|-60.98
VC|Saint Vincent and the Grenadines|12.98|-61.3
WS|Samoa|-13.8|-172.1
SM|San Marino|43.9|12.5
ST|Sao Tome and Principe|0.2|6.6
SA|Saudi Arabia|23.9|45.1
SN|Senegal|14.5|-14.5
RS|Serbia|44.0|21.0
SC|Seychelles|-4.7|55.5
SL|Sierra Leone|8.5|-11.8
SG|Singapore|1.35|103.8
SK|Slovakia|48.7|19.7
SI|Slovenia|46.2|14.99
SB|Solomon Islands|-9.6|160.2
SO|Somalia|5.2|46.2
ZA|South Africa|-30.6|22.9
KR|South Korea|35.9|127.8
SS|South Sudan|6.9|31.3
ES|Spain|40.5|-3.7
LK|Sri Lanka|7.9|80.8
SD|Sudan|12.9|30.2
SR|Suriname|3.9|-56.0
SE|Sweden|60.1|18.6
CH|Switzerland|46.8|8.2
SY|Syria|34.8|38.997
TW|Taiwan|23.7|121.0
TJ|Tajikistan|38.9|71.3
TZ|Tanzania|-6.4|34.9
TH|Thailand|15.9|100.99
TL|Timor-Leste|-8.9|125.7
TG|Togo|8.6|0.8
TO|Tonga|-21.2|-175.2
TT|Trinidad and Tobago|10.7|-61.2
TN|Tunisia|33.9|9.5
TR|Turkey|38.96|35.2
TM|Turkmenistan|38.97|59.6
UG|Uganda|1.4|32.3
UA|Ukraine|48.4|31.2
AE|United Arab Emirates|23.4|53.8
GB|United Kingdom|55.4|-3.4
US|United States|37.1|-95.7
UY|Uruguay|-32.5|-55.8
UZ|Uzbekistan|41.4|64.6
VU|Vanuatu|-15.4|166.96
VA|Vatican City|41.9|12.45
VE|Venezuela|6.4|-66.6
VN|Vietnam|14.1|108.3
YE|Yemen|15.6|48.5
ZM|Zambia|-13.1|27.8
ZW|Zimbabwe|-19.0|29.2
HK|Hong Kong|22.3|114.2
PR|Puerto Rico|18.2|-66.6
T;
    $T = [];
    foreach (explode("\n", $raw) as $line) {
        $p = explode('|', trim($line));
        if (count($p) === 4) $T[$p[0]] = ['c' => $p[0], 'n' => $p[1], 'lat' => (float)$p[2], 'lon' => (float)$p[3]];
    }
    return $T;
}

/* lower-case, accents removed, only letters / digits / spaces */
function earth_norm($s) {
    $s = mb_strtolower((string)$s, 'UTF-8');
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false && $t !== '') $s = $t; }
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/* name / alias / big city → ISO code (built once) */
function earth_lookup() {
    static $L = null;
    if ($L !== null) return $L;
    $L = [];
    foreach (earth_country_table() as $c) $L[earth_norm($c['n'])] = $c['c'];
    $alias = [
        'usa'=>'US','us'=>'US','u s a'=>'US','u s'=>'US','united states of america'=>'US','america'=>'US',
        'uk'=>'GB','u k'=>'GB','great britain'=>'GB','britain'=>'GB','england'=>'GB','scotland'=>'GB','wales'=>'GB','northern ireland'=>'GB',
        'uae'=>'AE','emirates'=>'AE','dubai'=>'AE','abu dhabi'=>'AE',
        'cote d ivoire'=>'CI','ivory coast'=>'CI','czechia'=>'CZ','holland'=>'NL','the netherlands'=>'NL','korea'=>'KR','republic of korea'=>'KR',
        'drc'=>'CD','dr congo'=>'CD','congo kinshasa'=>'CD','congo brazzaville'=>'CG','republic of the congo'=>'CG','swaziland'=>'SZ',
        'burma'=>'MM','cabo verde'=>'CV','east timor'=>'TL','russian federation'=>'RU','turkiye'=>'TR','viet nam'=>'VN','the gambia'=>'GM','the bahamas'=>'BS',
    ];
    foreach ($alias as $k => $v) $L[$k] = $v;
    $cities = [
        'NG' => 'lagos abuja ibadan kano port harcourt benin city kaduna enugu abeokuta ilorin jos owerri warri calabar uyo onitsha akure asaba maiduguri',
        'US' => 'new york los angeles chicago houston phoenix philadelphia san antonio san diego dallas san jose austin atlanta miami seattle boston denver washington dc las vegas detroit',
        'GB' => 'london manchester birmingham liverpool leeds glasgow edinburgh bristol cardiff belfast sheffield',
        'CA' => 'toronto vancouver montreal ottawa calgary edmonton winnipeg',
        'GH' => 'accra kumasi tamale takoradi',          'KE' => 'nairobi mombasa kisumu',        'ZA' => 'johannesburg cape town durban pretoria',
        'IN' => 'mumbai delhi new delhi bangalore bengaluru hyderabad chennai kolkata pune', 'PK' => 'karachi lahore islamabad',
        'FR' => 'paris marseille lyon',                 'DE' => 'berlin munich hamburg frankfurt cologne', 'ES' => 'madrid barcelona valencia seville',
        'IT' => 'rome milan naples turin',              'BR' => 'sao paulo rio de janeiro brasilia salvador',  'MX' => 'mexico city guadalajara monterrey',
        'EG' => 'cairo alexandria giza',                'TR' => 'istanbul ankara izmir',          'SA' => 'riyadh jeddah mecca',
        'AU' => 'sydney melbourne brisbane perth',      'JP' => 'tokyo osaka kyoto',              'CN' => 'beijing shanghai shenzhen guangzhou',
        'RU' => 'moscow saint petersburg',              'ID' => 'jakarta surabaya bali',          'PH' => 'manila cebu quezon city',
        'ET' => 'addis ababa',                          'TZ' => 'dar es salaam dodoma',           'UG' => 'kampala',    'SN' => 'dakar',
        'CM' => 'douala yaounde',                       'CI' => 'abidjan',                        'MA' => 'casablanca rabat',  'DZ' => 'algiers',
    ];
    /* city lists are written space-separated; multi-word cities are listed explicitly here */
    $multi = [
        'port harcourt'=>'NG','benin city'=>'NG','new york'=>'US','los angeles'=>'US','san antonio'=>'US','san diego'=>'US','san jose'=>'US','las vegas'=>'US','washington dc'=>'US',
        'new delhi'=>'IN','cape town'=>'ZA','sao paulo'=>'BR','rio de janeiro'=>'BR','mexico city'=>'MX','saint petersburg'=>'RU','quezon city'=>'PH','addis ababa'=>'ET',
        'dar es salaam'=>'TZ','abu dhabi'=>'AE',
    ];
    foreach ($multi as $k => $v) $L[$k] = $v;
    foreach ($cities as $cc => $list) {
        $l = $list;
        foreach ($multi as $k => $v) $l = str_replace($k, '', $l);
        foreach (preg_split('/\s+/', trim($l)) as $w) if ($w !== '' && !isset($L[$w])) $L[$w] = $cc;
    }
    return $L;
}

/* "Lagos, Nigeria" → 'NG'  (null when the text names no known country or big city) */
function earth_country_of($loc) {
    $L = earth_lookup();
    $parts = array_reverse(array_filter(array_map('earth_norm', explode(',', (string)$loc)), 'strlen'));
    foreach ($parts as $p) { if (isset($L[$p])) return $L[$p]; }
    /* no commas / not a clean part: try the whole text, then the last one to three words */
    $all = earth_norm($loc);
    if ($all !== '') {
        if (isset($L[$all])) return $L[$all];
        $w = explode(' ', $all);
        for ($n = min(3, count($w)); $n >= 1; $n--) {
            $tail = implode(' ', array_slice($w, -$n));
            if (isset($L[$tail])) return $L[$tail];
        }
        for ($n = min(3, count($w)); $n >= 1; $n--) {
            $head = implode(' ', array_slice($w, 0, $n));
            if (isset($L[$head])) return $L[$head];
        }
    }
    return null;
}