<?php
namespace verbb\formie\models;

use Craft;
use craft\base\Model;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class Phone extends Model
{
    // Static Methods
    // =========================================================================

    public static function toPhoneString(mixed $value): string
    {
        $number = $value;

        try {
            // Try and parse the number. Will fail if not provided in international format.
            $phoneUtil = PhoneNumberUtil::getInstance();
            $numberProto = $phoneUtil->parse($value);
            $number = $phoneUtil->format($numberProto, PhoneNumberFormat::INTERNATIONAL);
        } catch (Throwable $e) {
            // Do nothing, an invalid number
        }

        return str_replace(' ', '', $number);
    }


    // Properties
    // =========================================================================

    public ?string $number = null;
    public ?string $country = null;
    public ?bool $hasCountryCode = null;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        // Normalize the settings. Included in `toArray` for integrations, but not actively in use. Potentially refactor.
        if (array_key_exists('countryCode', $config)) {
            unset($config['countryCode']);
        }

        if (array_key_exists('countryName', $config)) {
            unset($config['countryName']);
        }

        parent::__construct($config);
    }

    /**
     * Returns the formatted phone number.
     *
     * @return string
     */
    public function __toString()
    {
        if ($this->hasCountryCode) {
            try {
                $phoneUtil = PhoneNumberUtil::getInstance();
                $numberProto = $phoneUtil->parse($this->number, $this->country);

                return $phoneUtil->format($numberProto, PhoneNumberFormat::INTERNATIONAL);
            } catch (NumberParseException $e) {
                if ($this->number && is_numeric($this->number)) {
                    // Consider the number still valid
                    $countryString = $this->country && is_numeric($this->country) ? '(' . $this->country . ') ' : '';

                    return $countryString . $this->number;
                }

                return '';
            }
        } else {
            return (string)$this->number;
        }
    }

    public function getCountryCode(): string
    {
        if ($this->hasCountryCode) {
            try {
                $phoneUtil = PhoneNumberUtil::getInstance();
                $numberProto = $phoneUtil->parse($this->number, $this->country);
                $countryCode = $numberProto->getCountryCode();

                if ($countryCode) {
                    return '+' . $countryCode;
                }
            } catch (Throwable $e) {

            }
        }

        return '';
    }

    public function getCountryName(): string
    {
        if ($this->country) {
            try {
                return Craft::$app->getAddresses()->getCountryRepository()->get($this->country)->getName();
            } catch (Throwable $e) {

            }
        }

        return '';
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        $array = parent::toArray($fields, $expand, $recursive);

        // Allow extra data to be serialized
        $array['countryCode'] = $this->getCountryCode();
        $array['countryName'] = $this->getCountryName();

        return $array;
    }

}
