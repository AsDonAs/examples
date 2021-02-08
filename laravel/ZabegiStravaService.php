<?php

namespace App\Services;

use Iamstuartwilson\StravaApi;

/**
 * Class ZabegiStravaService
 * @package App\Services
 */
class ZabegiStravaService
{
    /**
     * @var array $authData
     */
    private static $authData = [
        "access_token",
        "refresh_token",
        "expires_at",
    ];

    /**
     * @return string
     */
    public static function generateAuthLink()
    {
        $stravaApiID = env("STRAVA_API_ID");
        $stravaApiSecret = env("STRAVA_API_SECRET");

        $api = new StravaApi($stravaApiID, $stravaApiSecret);

        $stravaCallbackUrl = env("STRAVA_REDIRECT_URL");

        $authLink = $api->authenticationUrl($stravaCallbackUrl, 'auto', 'activity:read_all');

        return $authLink;
    }

    /**
     * @param $authCode
     * @return array|null
     */
    public static function getAuthData($authCode)
    {
        $stravaApiID = env("STRAVA_API_ID");
        $stravaApiSecret = env("STRAVA_API_SECRET");

        $api = new StravaApi($stravaApiID, $stravaApiSecret);

        try {
            $result = $api->tokenExchange($authCode);
        } catch (\Exception $e) {
            return null;
        }

        $authData = [];

        foreach (self::getAuthDataKeys() as $key) {
            $value = $result->$key ?? null;
            $authData[$key] = $value;
        }

        return $authData;
    }

    /**
     * @param array $authData
     * @param string $startDateTimestamped
     * @param integer $length
     * @return array|null
     */
    public static function getList($authData, $startDateTimestamped, $length)
    {
        $stravaApiID = env("STRAVA_API_ID");
        $stravaApiSecret = env("STRAVA_API_SECRET");

        $api = new StravaApi($stravaApiID, $stravaApiSecret);

        $accessToken = $authData["access_token"] ?? null;
        $refreshToken = $authData["refresh_token"] ?? null;
        $expiresAt = $authData["expires_at"] ?? null;

        try {
            $api->setAccessToken(
                $accessToken,
                $refreshToken,
                $expiresAt
            );
        } catch (\Exception $exception) {
            return null;
        }

        try {
            $notEnd = true;
            $page = 1;

            $results = [];

            while ($notEnd) {
                $response = $api->get("/athlete/activities", [
                    "after" => $startDateTimestamped,
                    "page" => $page,
                ]);

                if (is_object($response) and isset($response->errors) and !empty($response->errors)) {
                    return null;
                }

                if (is_array($response)) {
                    /** @var \stdClass $object */
                    foreach ($response as $object) {
                        $results[] = $object;
                    }

                    if (count($response) < 30) {
                        $notEnd = false;
                    }
                }
            }
        } catch (\Exception $e) {
            return null;
        }

        $results = self::processResults($results, $length);

        return $results;
    }

    /**
     * @return array
     */
    public static function getAuthDataKeys()
    {
        return self::$authData;
    }

    /**
     * @param array $results
     * @param integer $length
     * @return array
     */
    public static function processResults($results, $length)
    {
        $processedResults = [];

        /** @var \stdClass $result */
        foreach ($results as $result) {
            if ((!self::isManual($result))
                and (self::isCorrectLength($result, $length))) {
                $processedResults[] = $result;
            }
        }

        usort($processedResults, function ($first, $second) {
            $firstDate = $first->start_date ?? null;
            $secondDate = $second->start_date ?? null;

            if (is_null($firstDate) and is_null($secondDate)) {
                return 0;
            }

            if ($firstDate == $secondDate) {
                return 0;
            }

            if (is_null($firstDate)) {
                return -1;
            }

            if (is_null($secondDate)) {
                return 1;
            }

            $firstDateTime = new \DateTime($firstDate);
            $secondDateTime = new \DateTime($secondDate);

            return ($firstDateTime < $secondDateTime) ? -1 : 1;
        });

        $processedResults = array_reverse($processedResults);

        $processedResults = array_slice($processedResults, 0, 5);

        return $processedResults;
    }

    /**
     * @param \stdClass $result
     * @return bool
     */
    private static function isManual($result)
    {
        $manual = $result->manual ?? null;

        if (($manual == true) or ($manual == "true")) {
            return true;
        }

        return false;
    }

    /**
     * @param \stdClass $result
     * @param integer $length
     * @return bool
     */
    private static function isCorrectLength($result, $length)
    {
        $resultLength = $result->distance ?? 0;

        if (($resultLength >= $length) and ($resultLength <= ($length + 500))) {
            return true;
        }

        return false;
    }
}
