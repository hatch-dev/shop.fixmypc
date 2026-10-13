/*
 * One place for the rules that make an address hang together, so the address
 * book and guest checkout cannot drift apart on what a valid address is.
 *
 * The order is a hierarchy, and each level is only answerable once the level
 * above it is known:
 *
 *   country -> region (county / state) -> town -> postcode
 *
 * The country decides which regions exist, whether a postcode is wanted at
 * all and what shape it takes. The region decides which towns are offered.
 * The postcode is checked back against the region, because in Ireland the
 * first three characters of an Eircode name the area it was issued for.
 */

/*
 * Countries that run no postal code system at all. Asking one of these
 * customers for a postcode - and refusing the address without one - is the
 * bug this list exists to prevent.
 */
export const NO_POSTCODE = [
  'AE', 'AG', 'AO', 'AW', 'BF', 'BI', 'BJ', 'BS', 'BW', 'BZ', 'CD', 'CF',
  'CG', 'CI', 'CK', 'CM', 'CW', 'DJ', 'DM', 'ER', 'FJ', 'GD', 'GH', 'GM',
  'GQ', 'GY', 'HK', 'JM', 'KI', 'KM', 'KN', 'KP', 'LC', 'LY', 'ML', 'MO',
  'MR', 'MS', 'MU', 'MW', 'NR', 'NU', 'PA', 'QA', 'RW', 'SB', 'SC', 'SL',
  'SO', 'SR', 'ST', 'SY', 'TF', 'TL', 'TO', 'TT', 'TV', 'TZ', 'UG', 'VU',
  'YE', 'ZW'
]

/*
 * Per country: what the field is called, what a real one looks like, and the
 * pattern it has to match once the spaces are taken out. `space` is where the
 * value is broken for display, so what is typed reads back the way it is
 * printed on an envelope.
 */
export const POSTCODE_RULES = {
  IE: {
    label: 'Eircode',
    example: 'A65 F4E2',
    // routing key - one of the 15 letters An Post uses plus two digits, D6W
    // being the documented exception - then four characters from the reduced
    // alphabet that drops the letters easily confused with digits
    pattern: /^(?:[AC-FHKNPRTV-Y]\d{2}|D6W)[0-9AC-FHKNPRTV-Y]{4}$/,
    length: 7,
    space: 3,
    hint: 'Seven characters, for example A65 F4E2.'
  },
  GB: {
    label: 'Postcode',
    example: 'SW1A 1AA',
    pattern: /^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/,
    length: 7,
    spaceFromEnd: 3,
    hint: 'For example SW1A 1AA.'
  },
  US: {
    label: 'ZIP code',
    example: '10001',
    pattern: /^\d{5}(?:\d{4})?$/,
    length: 9,
    space: 5,
    spacer: '-',
    hint: 'Five digits, or ZIP+4 as 10001-2345.'
  },
  CA: {
    label: 'Postal code',
    example: 'K1A 0B1',
    pattern: /^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$/,
    length: 6,
    space: 3,
    hint: 'For example K1A 0B1.'
  },
  IN: {
    label: 'PIN code',
    example: '110001',
    // a PIN code never starts with 0
    pattern: /^[1-9]\d{5}$/,
    length: 6,
    hint: 'Six digits, for example 110001.'
  },
  AU: {label: 'Postcode', example: '2000', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  NZ: {label: 'Postcode', example: '6011', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  DE: {label: 'Postleitzahl', example: '10115', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  FR: {label: 'Code postal', example: '75001', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  ES: {label: 'Codigo postal', example: '28001', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  IT: {label: 'CAP', example: '00184', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  NL: {
    label: 'Postcode',
    example: '1012 AB',
    pattern: /^\d{4}[A-Z]{2}$/,
    length: 6,
    space: 4,
    hint: 'Four digits then two letters.'
  },
  BE: {label: 'Postcode', example: '1000', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  AT: {label: 'PLZ', example: '1010', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  CH: {label: 'PLZ', example: '8001', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  DK: {label: 'Postnummer', example: '1050', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  NO: {label: 'Postnummer', example: '0150', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  FI: {label: 'Postinumero', example: '00100', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  SE: {
    label: 'Postnummer',
    example: '111 21',
    pattern: /^\d{5}$/,
    length: 5,
    space: 3,
    hint: 'Five digits.'
  },
  PL: {
    label: 'Kod pocztowy',
    example: '00-001',
    pattern: /^\d{5}$/,
    length: 5,
    space: 2,
    spacer: '-',
    hint: 'Five digits, written 00-001.'
  },
  PT: {
    label: 'Codigo postal',
    example: '1000-001',
    pattern: /^\d{7}$/,
    length: 7,
    space: 4,
    spacer: '-',
    hint: 'Seven digits, written 1000-001.'
  },
  JP: {
    label: 'Postal code',
    example: '100-0001',
    pattern: /^\d{7}$/,
    length: 7,
    space: 3,
    spacer: '-',
    hint: 'Seven digits, written 100-0001.'
  },
  BR: {
    label: 'CEP',
    example: '01310-100',
    pattern: /^\d{8}$/,
    length: 8,
    space: 5,
    spacer: '-',
    hint: 'Eight digits, written 01310-100.'
  },
  ZA: {label: 'Postal code', example: '0001', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  SG: {label: 'Postal code', example: '238823', pattern: /^\d{6}$/, length: 6, hint: 'Six digits.'},
  MY: {label: 'Postcode', example: '50450', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  CN: {label: 'Postal code', example: '100000', pattern: /^\d{6}$/, length: 6, hint: 'Six digits.'},
  MX: {label: 'Codigo postal', example: '01000', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  RU: {label: 'Postal code', example: '101000', pattern: /^\d{6}$/, length: 6, hint: 'Six digits.'},
  PK: {label: 'Postal code', example: '44000', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  BD: {label: 'Postal code', example: '1000', pattern: /^\d{4}$/, length: 4, hint: 'Four digits.'},
  LK: {label: 'Postal code', example: '00100', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'},
  NP: {label: 'Postal code', example: '44600', pattern: /^\d{5}$/, length: 5, hint: 'Five digits.'}
}

/*
 * Anywhere not listed above still has a postal code, we just do not know its
 * shape - so it is asked for and only checked for being plausible.
 */
export const GENERIC_POSTCODE = {
  label: 'Postal code',
  example: '',
  pattern: /^[A-Z0-9-]{3,10}$/,
  length: 12,
  hint: ''
}

/*
 * Eircode routing keys against the county they were issued for. Used to tell
 * a customer their Eircode and their county disagree - as a warning, never a
 * refusal, because An Post adds keys and a few of them straddle a boundary.
 * A key that is not listed is not questioned.
 */
export const EIRCODE_COUNTY = {
  A41: 'CN', A42: 'MH', A45: 'MH', A63: 'WW', A67: 'WW', A75: 'MN', A81: 'MN',
  A82: 'CN', A83: 'CN', A84: 'MH', A85: 'MH', A86: 'MH', A91: 'LH', A92: 'LH',
  A94: 'D', A96: 'D', A98: 'WW',

  C15: 'KE',

  E21: 'TA', E25: 'TA', E32: 'TA', E34: 'TA', E41: 'OY', E45: 'OY', E53: 'OY',
  E91: 'TA',

  F12: 'MO', F23: 'MO', F26: 'MO', F28: 'MO', F31: 'MO', F35: 'MO', F42: 'MO',
  F45: 'RN', F52: 'RN', F56: 'RN', F91: 'SO', F92: 'DL', F93: 'DL', F94: 'DL',

  H12: 'LM', H14: 'LD', H16: 'LD', H18: 'LM', H23: 'MN', H53: 'G', H54: 'G',
  H62: 'G', H65: 'G', H71: 'G', H91: 'G',

  K32: 'D', K34: 'D', K36: 'D', K45: 'D', K56: 'D', K67: 'D', K78: 'D',

  N37: 'WH', N39: 'WH', N41: 'LD', N91: 'MH',

  P12: 'C', P14: 'C', P17: 'C', P24: 'C', P25: 'C', P31: 'C', P32: 'C',
  P36: 'C', P43: 'C', P47: 'C', P51: 'C', P56: 'C', P61: 'C', P67: 'C',
  P72: 'C', P75: 'C', P81: 'C', P85: 'C',

  R14: 'KE', R21: 'KE', R32: 'LS', R35: 'LS', R42: 'LS', R45: 'OY', R51: 'KE',
  R56: 'WW', R93: 'CW', R95: 'KK',

  T12: 'C', T23: 'C', T34: 'C', T45: 'C', T56: 'C',

  V14: 'LK', V15: 'CE', V23: 'KY', V31: 'KY', V35: 'LK', V42: 'LK', V92: 'KY',
  V93: 'KY', V94: 'LK', V95: 'CE',

  W12: 'KE', W23: 'KE', W34: 'KE', W91: 'KE',

  X35: 'WD', X42: 'WX', X91: 'WD',

  Y14: 'WX', Y21: 'WX', Y25: 'WX', Y34: 'WX', Y35: 'WX'
}

/* The value the town select carries for "my town is not on the list". */
export const TOWN_OTHER = '__other__'

/*
 * Towns offered once a county is chosen, as either a bare name or a
 * [name, routing key] pair where that town's Eircode prefix is known. The
 * key is what lets the postcode be checked against the town and not just the
 * county - "T12 with Dundalk picked" is wrong twice over, and only the town
 * says so precisely.
 *
 * Towns without a key still get the county check, which is the one that
 * always holds. No list of Irish towns is complete, so every form that reads
 * this offers TOWN_OTHER alongside it and takes a typed name.
 */
export const IE_TOWNS = {
  CW: [['Carlow', 'R93'], 'Tullow', 'Bagenalstown', 'Muine Bheag', 'Hacketstown', 'Leighlinbridge', 'Borris', 'Rathvilly', 'Ballon', 'Myshall', 'Clonegal', 'Fenagh', 'Tinryland', 'Palatine', 'Graiguecullen'],
  CN: [['Cavan', 'A82'], ['Bailieborough', 'A83'], ['Ballyjamesduff', 'A41'], 'Belturbet', 'Cootehill', 'Kingscourt', 'Virginia', 'Ballyconnell', 'Arva', 'Killeshandra', 'Shercock', 'Mullagh', 'Butlersbridge', 'Blacklion', 'Dowra', 'Kilnaleck', 'Crossdoney', 'Redhills', 'Swanlinbar', 'Bawnboy', 'Ballinagh', 'Stradone'],
  CE: [['Ennis', 'V95'], ['Kilrush', 'V15'], 'Shannon', 'Killaloe', 'Ennistymon', 'Lahinch', 'Sixmilebridge', 'Newmarket-on-Fergus', 'Kilkee', 'Miltown Malbay', 'Scarriff', 'Tulla', 'Corofin', 'Doolin', 'Lisdoonvarna', 'Ballyvaughan', 'Clarecastle', 'Quin', 'Kildysart', 'Broadford', 'Feakle', 'Mountshannon', 'Bunratty', 'Cratloe', 'Parteen', 'Kilmihil', 'Inagh', 'Ruan', 'Crusheen'],
  C: [['Cork', 'T12'], 'Ballincollig', 'Carrigaline', 'Cobh', 'Douglas', 'Blarney', 'Glanmire', 'Passage West', 'Mallow', 'Midleton', 'Youghal', 'Bandon', 'Clonakilty', 'Fermoy', 'Kinsale', 'Macroom', 'Skibbereen', 'Bantry', 'Charleville', 'Mitchelstown', 'Dunmanway', 'Kanturk', 'Millstreet', 'Newmarket', 'Buttevant', 'Castletownbere', 'Schull', 'Baltimore', 'Rosscarbery', 'Courtmacsherry', 'Crosshaven', 'Carrigtwohill', 'Whitegate', 'Watergrasshill', 'Ballycotton', 'Castlemartyr', 'Killeagh', 'Coachford', 'Ballyvourney', 'Dripsey', 'Riverstick', 'Innishannon'],
  DL: [['Letterkenny', 'F92'], ['Donegal', 'F93'], ['Ballyshannon', 'F94'], 'Buncrana', 'Ballybofey', 'Stranorlar', 'Bundoran', 'Carndonagh', 'Moville', 'Lifford', 'Killybegs', 'Dungloe', 'Ardara', 'Glenties', 'Ramelton', 'Rathmullan', 'Milford', 'Dunfanaghy', 'Falcarragh', 'Gweedore', 'Creeslough', 'Muff', 'Newtowncunningham', 'Raphoe', 'Convoy', 'Ballintra', 'Mountcharles', 'Kilcar', 'Carrick', 'Burtonport'],
  D: [['Dublin', 'D'], ['Blackrock', 'A94'], ['Glenageary', 'A96'], ['Balbriggan', 'K32'], ['Skerries', 'K34'], ['Malahide', 'K36'], ['Lusk', 'K45'], ['Rush', 'K56'], ['Swords', 'K67'], ['Clondalkin', 'K78'], 'Blanchardstown', 'Tallaght', 'Lucan', 'Dun Laoghaire', 'Howth', 'Portmarnock', 'Sutton', 'Baldoyle', 'Donabate', 'Sandyford', 'Dundrum', 'Stillorgan', 'Booterstown', 'Dalkey', 'Killiney', 'Shankill', 'Rathfarnham', 'Templeogue', 'Firhouse', 'Palmerstown', 'Castleknock', 'Clonsilla', 'Finglas', 'Santry', 'Coolock', 'Raheny', 'Clontarf', 'Ballsbridge', 'Rathmines', 'Ranelagh', 'Terenure', 'Crumlin', 'Drimnagh', 'Ballyfermot', 'Saggart', 'Rathcoole', 'Newcastle', 'Garristown', 'Oldtown', 'Naul'],
  G: [['Galway', 'H91'], ['Ballinasloe', 'H53'], ['Loughrea', 'H54'], ['Tuam', 'H62'], ['Gort', 'H65'], ['Clifden', 'H71'], 'Athenry', 'Oranmore', 'Oughterard', 'Portumna', 'Headford', 'Moycullen', 'Barna', 'Spiddal', 'Salthill', 'Claregalway', 'Craughwell', 'Kinvara', 'Dunmore', 'Glenamaddy', 'Mountbellew', 'Woodford', 'Roundstone', 'Letterfrack', 'Carna', 'Rosmuc', 'Kilcolgan', 'Ardrahan', 'Corrandulla', 'Milltown'],
  KY: [['Tralee', 'V92'], ['Killarney', 'V93'], ['Listowel', 'V31'], 'Dingle', 'Kenmare', 'Castleisland', 'Killorglin', 'Caherciveen', 'Killarney', 'Ballybunion', 'Tarbert', 'Abbeyfeale', 'Sneem', 'Waterville', 'Ballyheigue', 'Castlegregory', 'Camp', 'Annascaul', 'Milltown', 'Farranfore', 'Rathmore', 'Kilgarvan', 'Ardfert', 'Fenit', 'Cahersiveen', 'Portmagee', 'Glenbeigh', 'Beaufort'],
  KE: [['Naas', 'W91'], ['Maynooth', 'C15'], ['Celbridge', 'W23'], ['Kildare', 'R51'], ['Athy', 'R14'], 'Newbridge', 'Leixlip', 'Clane', 'Kilcock', 'Monasterevin', 'Sallins', 'Kilcullen', 'Rathangan', 'Prosperous', 'Straffan', 'Johnstown', 'Castledermot', 'Ballymore Eustace', 'Derrinturn', 'Allenwood', 'Robertstown', 'Newtown', 'Kill', 'Caragh', 'Suncroft', 'Nurney'],
  KK: [['Kilkenny', 'R95'], 'Callan', 'Castlecomer', 'Thomastown', 'Graiguenamanagh', 'Ferrybank', 'Bennettsbridge', 'Freshford', 'Urlingford', 'Johnstown', 'Piltown', 'Mooncoin', 'Inistioge', 'Gowran', 'Paulstown', 'Kilmacow', 'Mullinavat', 'Ballyragget', 'Stoneyford'],
  LS: [['Portlaoise', 'R32'], 'Portarlington', 'Mountmellick', 'Abbeyleix', 'Rathdowney', 'Mountrath', 'Stradbally', 'Durrow', 'Ballylinan', 'Ballinakill', 'Clonaslee', 'Borris-in-Ossory', 'Emo', 'Killenard', 'Timahoe', 'Castletown'],
  LM: [['Carrick-on-Shannon', 'H12'], ['Manorhamilton', 'H18'], 'Ballinamore', 'Drumshanbo', 'Mohill', 'Dromahair', 'Kinlough', 'Leitrim', 'Drumkeeran', 'Ballinaglera', 'Carrigallen', 'Keshcarrigan', 'Rossinver', 'Tullaghan'],
  LK: [['Limerick', 'V94'], ['Newcastle West', 'V42'], 'Abbeyfeale', 'Kilmallock', 'Rathkeale', 'Adare', 'Castleconnell', 'Croom', 'Bruff', 'Askeaton', 'Foynes', 'Patrickswell', 'Annacotty', 'Caherconlish', 'Cappamore', 'Doon', 'Hospital', 'Kilfinane', 'Dromcollogher', 'Ballingarry', 'Pallaskenry', 'Murroe', 'Bruree', 'Glin', 'Shanagolden'],
  LD: ['Longford', 'Granard', 'Edgeworthstown', 'Ballymahon', 'Lanesborough', 'Drumlish', 'Newtownforbes', 'Ardagh', 'Killashee', 'Legan', 'Aughnacliffe', 'Moydow', 'Kenagh'],
  LH: [['Dundalk', 'A91'], ['Drogheda', 'A92'], 'Ardee', 'Carlingford', 'Blackrock', 'Dunleer', 'Termonfeckin', 'Clogherhead', 'Castlebellingham', 'Louth', 'Omeath', 'Knockbridge', 'Tallanstown', 'Collon', 'Baltray'],
  MO: [['Castlebar', 'F23'], ['Ballina', 'F12'], ['Westport', 'F28'], ['Claremorris', 'F26'], ['Ballinrobe', 'F31'], ['Swinford', 'F35'], ['Belmullet', 'F42'], 'Knock', 'Ballyhaunis', 'Foxford', 'Charlestown', 'Kiltimagh', 'Newport', 'Louisburgh', 'Ballycastle', 'Killala', 'Crossmolina', 'Balla', 'Cong', 'Achill Sound', 'Mulranny', 'Bangor Erris', 'Tourmakeady', 'Partry', 'Straide', 'Bohola'],
  MH: [['Trim', 'N91'], ['Kells', 'A42'], ['Ashbourne', 'A86'], ['Dunboyne', 'A85'], ['Dunshaughlin', 'A84'], ['Enfield', 'A45'], 'Navan', 'Ratoath', 'Laytown', 'Bettystown', 'Athboy', 'Oldcastle', 'Slane', 'Duleek', 'Stamullen', 'Clonee', 'Summerhill', 'Longwood', 'Kilcock', 'Nobber', 'Kilmessan', 'Dunmoe', 'Julianstown', 'Donore', 'Rathmolyon', 'Ballivor', 'Kildalkey'],
  MN: [['Castleblayney', 'A75'], ['Carrickmacross', 'A81'], ['Clones', 'H23'], 'Monaghan', 'Ballybay', 'Emyvale', 'Glaslough', 'Scotstown', 'Smithborough', 'Newbliss', 'Rockcorry', 'Threemilehouse', 'Inniskeen', 'Latton'],
  OY: [['Tullamore', 'E53'], ['Birr', 'E41'], ['Edenderry', 'E45'], 'Clara', 'Banagher', 'Portarlington', 'Ferbane', 'Kilcormac', 'Daingean', 'Rhode', 'Shinrone', 'Cloghan', 'Moneygall', 'Geashill', 'Tullamore', 'Belmont', 'Clonbullogue'],
  RN: [['Roscommon', 'F52'], ['Boyle', 'F56'], ['Castlerea', 'F45'], 'Ballaghaderreen', 'Strokestown', 'Elphin', 'Frenchpark', 'Ballinlough', 'Athleague', 'Knockcroghery', 'Ballyforan', 'Keadue', 'Arigna', 'Croghan', 'Tarmonbarry', 'Ballinasloe'],
  SO: [['Sligo', 'F91'], 'Ballymote', 'Tubbercurry', 'Enniscrone', 'Collooney', 'Grange', 'Strandhill', 'Rosses Point', 'Ballintogher', 'Coolaney', 'Easkey', 'Dromore West', 'Riverstown', 'Gurteen', 'Cliffoney'],
  TA: [['Nenagh', 'E34'], ['Thurles', 'E91'], ['Tipperary', 'E21'], ['Cashel', 'E25'], ['Roscrea', 'E32'], 'Clonmel', 'Carrick-on-Suir', 'Templemore', 'Cahir', 'Fethard', 'Killenaule', 'Newport', 'Borrisokane', 'Borrisoleigh', 'Cloughjordan', 'Ballina', 'Portroe', 'Dundrum', 'Golden', 'Ardfinnan', 'Clogheen', 'Ballyporeen', 'Littleton', 'Holycross'],
  WD: [['Waterford', 'X91'], ['Dungarvan', 'X35'], 'Tramore', 'Lismore', 'Portlaw', 'Dunmore East', 'Cappoquin', 'Tallow', 'Ardmore', 'Kilmacthomas', 'Passage East', 'Stradbally', 'Ballymacarbry', 'Bunmahon', 'Ring', 'Clashmore'],
  WH: [['Mullingar', 'N39'], ['Athlone', 'N37'], 'Moate', 'Kinnegad', 'Castlepollard', 'Kilbeggan', 'Rochfortbridge', 'Delvin', 'Killucan', 'Multyfarnham', 'Ballynacargy', 'Tyrrellspass', 'Clonmellon', 'Coole', 'Glasson', 'Milltownpass'],
  WX: [['Wexford', 'Y35'], ['Gorey', 'Y14'], ['Enniscorthy', 'Y21'], ['New Ross', 'Y34'], 'Bunclody', 'Rosslare', 'Rosslare Harbour', 'Courtown', 'Ferns', 'Taghmon', 'Bridgetown', 'Kilmore Quay', 'Duncannon', 'Fethard-on-Sea', 'Castlebridge', 'Curracloe', 'Blackwater', 'Oulart', 'Camolin', 'Clonroche', 'Adamstown'],
  WW: [['Bray', 'A98'], ['Greystones', 'A63'], ['Wicklow', 'A67'], ['Blessington', 'R56'], 'Arklow', 'Baltinglass', 'Newtownmountkennedy', 'Rathdrum', 'Ashford', 'Aughrim', 'Carnew', 'Tinahely', 'Dunlavin', 'Enniskerry', 'Kilcoole', 'Delgany', 'Roundwood', 'Laragh', 'Avoca', 'Shillelagh', 'Donard', 'Kilpedder']
}

/** The postcode rule for a country, or null where the country has none. */
export function postcodeRule(country) {
  const code = (country || '').toUpperCase()

  if (!code || NO_POSTCODE.includes(code)) {
    return null
  }

  return POSTCODE_RULES[code] || GENERIC_POSTCODE
}

/** Strip a postcode back to the characters the pattern is written against. */
export function normalisePostcode(country, value) {
  return (value || '').replace(/[\s-]/g, '').toUpperCase()
}

/**
 * Re-space a postcode as it is typed, so the field reads back the way the
 * code is printed. Grouping is counted from the front, except in Britain,
 * where the inward half is the fixed three characters at the end.
 */
export function formatPostcode(country, value) {
  const rule = postcodeRule(country)

  if (!rule) {
    return (value || '').toUpperCase()
  }

  const raw = (value || '')
    .replace(/[^0-9A-Za-z]/g, '')
    .toUpperCase()
    .slice(0, rule.length)

  const spacer = rule.spacer || ' '

  if (rule.spaceFromEnd && raw.length > rule.spaceFromEnd) {
    const at = raw.length - rule.spaceFromEnd
    return `${raw.slice(0, at)}${spacer}${raw.slice(at)}`
  }

  if (rule.space && raw.length > rule.space) {
    return `${raw.slice(0, rule.space)}${spacer}${raw.slice(rule.space)}`
  }

  return raw
}

/** Whether a postcode matches the shape its country uses. */
export function isValidPostcode(country, value) {
  const rule = postcodeRule(country)

  if (!rule) {
    return true
  }

  return rule.pattern.test(normalisePostcode(country, value))
}

/** The county an Eircode was issued for, or null if the key is unknown. */
export function eircodeCounty(zip) {
  const key = normalisePostcode('IE', zip).slice(0, 3)

  if (!key) {
    return null
  }

  /*
   * D01 to D24 and D6W are the Dublin postal districts, and no key outside
   * Dublin begins with D - so the whole letter is covered by one line rather
   * than twenty-two entries in the table.
   */
  if (key[0] === 'D') {
    return 'D'
  }

  return EIRCODE_COUNTY[key] || null
}

/** Towns to offer for a county. Empty means "no suggestions, type freely". */
export function townSuggestions(country, state) {
  if ((country || '').toUpperCase() !== 'IE') {
    return []
  }

  const towns = (IE_TOWNS[state] || [])
    .map(entry => (Array.isArray(entry) ? entry[0] : entry))
    .sort((a, b) => a.localeCompare(b))

  // a few towns sit in more than one entry of the table; show each once
  return towns.filter((town, index) => towns.indexOf(town) === index)
}

/**
 * The Eircode routing key a town is served by, or null where the table does
 * not carry one - in which case the county check is the only one that runs.
 */
export function townRoutingKey(country, state, town) {
  if ((country || '').toUpperCase() !== 'IE' || !town) {
    return null
  }

  const wanted = town.trim().toLowerCase()

  const entry = (IE_TOWNS[state] || []).find(item =>
    Array.isArray(item) && item[0].toLowerCase() === wanted
  )

  return entry ? entry[1] : null
}

/*
 * A town is a name, not a code, so this only rules out what cannot be one:
 * too short, or made of digits and punctuation.
 */
export function isValidTown(value) {
  const town = (value || '').trim()

  return town.length >= 2 && /[A-Za-zÀ-ɏ]/.test(town) && !/\d/.test(town)
}

/*
 * The mixin half. A component supplies `addressCountry` and `states`, and
 * gets back everything the country decides about the levels below it.
 */
export default {
  data() {
    return {
      /* towns for the selected state, for every country except Ireland */
      fetchedTowns: [],
      fetchingTowns: false
    }
  },
  watch: {
    /*
     * The town list is a function of country and state together, so it is
     * reloaded whenever either moves. Watching rather than hanging it off the
     * change handlers means an address loaded for editing gets its list too.
     */
    addressCountry: {
      immediate: true,
      handler() {
        this.loadTowns()
      }
    },
    addressState() {
      this.loadTowns()
    }
  },
  computed: {
    postcodeRule() {
      return postcodeRule(this.addressCountry)
    },
    postcodeLabel() {
      return this.postcodeRule ? this.postcodeRule.label : 'Postal code'
    },
    postcodeRequired() {
      return !!this.postcodeRule
    },
    postcodeExample() {
      return this.postcodeRule ? this.postcodeRule.example : ''
    },
    postcodeHint() {
      return this.postcodeRule ? this.postcodeRule.hint : ''
    },
    postcodePlaceholder() {
      if (!this.postcodeRule) {
        return 'Not used in this country'
      }

      return this.postcodeExample || `Enter ${this.postcodeLabel.toLowerCase()}`
    },
    postcodeMaxLength() {
      if (!this.postcodeRule) {
        return 12
      }

      // room for the separator the formatter puts back in
      const spaced = this.postcodeRule.space || this.postcodeRule.spaceFromEnd

      return this.postcodeRule.length + (spaced ? 1 : 0)
    },
    isIreland() {
      return this.addressCountry === 'IE'
    },
    regionLabel() {
      return this.isIreland ? 'County' : 'State / Region'
    },
    hasRegions() {
      return Object.keys(this.states || {}).length > 0
    },
    regionPlaceholder() {
      if (!this.addressCountry) {
        return 'Choose a country first'
      }

      return this.hasRegions
        ? `Select ${this.regionLabel.toLowerCase()}`
        : 'Not applicable'
    },
    townPlaceholder() {
      if (!this.townReady) {
        return `Choose a ${this.regionLabel.toLowerCase()} first`
      }

      if (this.fetchingTowns) {
        return 'Loading towns...'
      }

      return this.hasTownOptions ? 'Select town or city' : 'Enter city'
    },
    /*
     * Town and postcode open as soon as there is a country, in whatever
     * order the customer fills them. The county only narrows the town list;
     * it no longer locks the fields below it.
     */
    townReady() {
      return !!this.addressCountry
    },
    postcodeReady() {
      return this.townReady
    },
    /*
     * Ireland's towns are held here, county by county, because they are the
     * only ones that come with the Eircode routing key each town is served
     * by. Every other country's are fetched - 130,000 towns is not something
     * to ship to a browser that needs one county's worth of them.
     */
    townOptions() {
      if (this.isIreland) {
        return townSuggestions(this.addressCountry, this.addressState)
      }

      return this.fetchedTowns || []
    },
    /* Where there is a list, the town is picked; elsewhere it is typed. */
    hasTownOptions() {
      return this.townOptions.length > 0
    },
    /*
     * The routing key the chosen town is served by, when the table knows it.
     * This is what narrows the Eircode check from "somewhere in the county"
     * to "this town".
     */
    townKey() {
      return townRoutingKey(this.addressCountry, this.addressState, this.addressCity)
    }
  },
  methods: {
    /*
     * One state's towns, from the API. Ireland never asks - its list is here
     * already. A country or state the server has no list for answers with an
     * empty array, which drops the form back to a typed town rather than
     * showing an empty dropdown.
     */
    async loadTowns() {
      if (this.isIreland || !this.addressCountry || !this.addressState) {
        this.fetchedTowns = []
        return
      }

      const country = this.addressCountry
      const state = this.addressState

      this.fetchingTowns = true

      try {
        const {data} = await this.getRequest({
          params: {country, state},
          api: 'towns'
        })

        // the county may have moved on while this was in flight
        if (country !== this.addressCountry || state !== this.addressState) {
          return
        }

        this.fetchedTowns = Array.isArray(data?.data) ? data.data : []

      } catch (e) {
        // a town can always be typed, so a failed lookup is not an error
        this.fetchedTowns = []

      } finally {
        this.fetchingTowns = false
      }
    },
    isValidPostcode(value, country) {
      return isValidPostcode(country === undefined ? this.addressCountry : country, value)
    },
    formatPostcode(value, country) {
      return formatPostcode(country === undefined ? this.addressCountry : country, value)
    },
    eircodeCounty,
    isValidTown,
    townRoutingKey,
    /*
     * The cross-check that closes the loop. An Eircode carries where it was
     * issued in its routing key, so it can be read back against what was
     * picked above it - against the town where the table knows that town's
     * key, and against the county otherwise.
     *
     * It is a warning and not an error. An Post adds routing keys, a few
     * straddle a boundary, and an address on the edge of a town is served by
     * the next town's key - so a stale table must never be able to refuse a
     * real delivery. Anything the table does not recognise says nothing.
     */
    postcodeRegionWarning(zip, state, states) {
      if (!this.isIreland || !zip || !state) {
        return ''
      }

      const key = normalisePostcode('IE', zip).slice(0, 3)

      if (key.length < 3) {
        return ''
      }

      const county = eircodeCounty(zip)

      if (!county) {
        return ''
      }

      // the county is the outer check: wrong county, wrong everything
      if (county !== state) {
        const name = (states || this.states || {})[county]?.name

        return name
          ? `This Eircode is registered to Co. ${name}. Check the county above.`
          : ''
      }

      /*
       * Right county, so the town is worth asking about - but only where the
       * town picked has a key of its own to compare against.
       */
      const town = this.townKey

      if (!town) {
        return ''
      }

      // Dublin's key is the whole D letter, not one of the numbered districts
      if (town === 'D') {
        return key[0] === 'D'
          ? ''
          : `This Eircode starts ${key}. Dublin addresses start D01 to D24, or D6W. Check the town or the Eircode.`
      }

      return key === town
        ? ''
        : `This Eircode starts ${key}, and ${this.addressCity} addresses start ${town}. Check the town or the Eircode.`
    }
  }
}
