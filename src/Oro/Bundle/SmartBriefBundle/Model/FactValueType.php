<?php

namespace Oro\Bundle\SmartBriefBundle\Model;

/**
 * Names of the built-in fact value types; projects register more as services tagged `oro_smart_brief.fact_value_type`.
 */
final class FactValueType
{
    public const string DATE = 'date';
    public const string INTEGER = 'integer';
    public const string PERCENT = 'percent';
    public const string MONEY = 'money';
    public const string TEXT = 'text';
    public const string ENUM_OPTION = 'enum_option';
    public const string CHOICE = 'choice';

    /** Joins the two ends of a range value of the `date` and `percent` types: `2026-09-03/2026-10-27`, `-13.0/8.3` */
    public const string RANGE_SEPARATOR = '/';
}
