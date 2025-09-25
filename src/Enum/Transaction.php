<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\Enum;

enum Transaction: string
{
    case DEMISE = 'B';
    case BUSINESS = 'F';
    case SALE_OF_CONSTRUCTION_PROMPTEUR = 'G'; // used for the profession "PROMOTEUR" ("Developer")
    case SALE_OF_CONSTRUCTION_CMI = 'H'; // used for the profession "CMI" ("Builder of detached houses")
    case AUCTION = 'I';
    case REAL_ESTATE_SERVICES = 'J';
    case RENT = 'L';
    case SALE_OR_RENT = 'M';
    case RENT_SAILING = 'P';
    case SEASONAL_RENT = 'S';
    case SALE = 'V';
    case LIFE_ANNUITY = 'W';
    case REAL_ESTATE_FOR_RENT_APPLICATION = 'Z';
}
