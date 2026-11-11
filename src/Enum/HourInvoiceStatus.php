<?php

namespace Base\Forge\Enum;

/**
 * Where an invoice of hours stands: a draft a person reviews (its time
 * entries reserved, nothing numbered yet), issued (a numbered
 * omnibase/marketplace Invoice, never edited again), cancelled by a credit
 * note (its time entries released for another invoice).
 */
enum HourInvoiceStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case CANCELLED = 'cancelled';
}
