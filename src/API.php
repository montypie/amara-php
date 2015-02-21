<?php
namespace AmaraPHPAccess;

/**
 * Amara API component
 *
 * This component provides an AmaraAPI object to perform common interactions
 * with Amara.org's API.
 *
 * It's a refactoring of code originally intended for automating tasks
 * from the command line, so it's pretty much alpha code.
 *
 * A lot of resources aren't handled yet, no optimizations have been performed,
 * and updates will break things. It's provided "as is" -- you must fully
 * audit it before using it.
 *
 * @author Fran Ontanaya
 * @copyright 2014 Fran Ontanaya
 * @license GPLv3
 * @version 0.4.0
 * @uses DummyLogger
 *
 * @todo Caching
 * @todo Add relevant logging events
 * @todo Validate everything.
 * @todo Support HTTPS
 * @todo Add DbC-style asserts
 */
class API {
    const VERSION = '0.4.1';

    /**
     * Credentials
     *
     * Each instance of the object is tied to a set of credentials.
     * Recycling the object with different credentials is probably
     * calling for trouble.
     *
     * @since 0.1.0
     */
    protected $host;
    protected $user;
    protected $apikey;

    /**
     * External dependencies
     *
     * These store supplied dependencies. Protected since they need validation
     * e.g. to ensure they are PSR compliant.
     *
     * Not implemented currently.
     *
     * @since 0.1.0
     */
    protected $logger;
    protected $cache;

    /**
     * Settings
     *
     * Properties that may be changed on the fly.
     *
     * Beware raising $limit (the number of records per request) too much,
     * requests that take longer than a minute time out e.g. on videos
     * with many languages.
     *
     * $total_limit caps how many records you can retrieve from traversable
     * resources. The default is already very high, but some resources like
     * team activity can have tens of thousands of records. You may want
     * to raise the total_limit temporarily for certain actions only,
     * so you don't have some request accidentally get caught trying to fetch
     * a huge batch of data. Or better, use the $offset argument when calling
     * traversable getX methods and aggregate the responses. This may
     * eventually be replacied with a Generator that yields the data
     * as required.
     *
     * @since 0.1.0
    */
    public $retries = 10;
    public $limit = 10;
    public $total_limit = 2000;
    public $verbose_curl = false;
    public $assers_enabled = true;

    /**
     * Initialization
     *
     * For Amara.org, $host should be:
     * https://www.amara.org/api2/partners/
     *
     * @since 0.1.0
    */
    function __construct($host, $user, $apikey) {
        $this->setAccount($host, $user, $apikey);
        $this->setLogger = new DummyLogger();
    }

    /**
     * Change accounts
     *
     * The key would be expected to be different on a different host.
     *
     * @since 0.1.0
     */
    function setAccount($host, $user, $apikey) {
        $this->validateAccount($host, $user, $apikey);
        if ($this->host !== $host && $this->apikey === $apikey) {
            $this->throwException(
                'InvalidAPIAccount',
                __METHOD__,
                'Different API key when changing hosts',
                'Same API key when changing hosts'
            );
        } elseif ($this->apikey !== $apikey && $this->user === $user) {
            $this->throwException(
                'InvalidAPIAccount',
                __METHOD__,
                'Different API key when changing usernames',
                'Same API key when changing usernames'
            );
        }
        $this->user = $user;
        $this->apikey = $apikey;
        $this->host = $host;
    }


    /**
     * Set a PSR-3 logger
     *
     * Rather than bloating the constructor with some optional dependencies, like
     * a PSR-3 logger, we construct with a dummy and let the user change the logger
     * later.
     *
     * User may expect the previous logger to not continue being used
     * after changing it, so if this fails, we set it as null.
     *
     * @since 0.1.0
     */
    function setLogger($logger) {
        if (!$this->isValidObject($logger, array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'))) {
            $this->logger = null;
            return false;
        }
        $this->logger = $logger;
        return true;
    }


    // cURL methods

    /**
     * Generates headers needed by Amara's API
     *
     * Most, but not all, requests and responses from Amara's API are JSON
     *
     * @since 0.1.0
     */
    function getHeader($ct = null) {
        assert($this->validateAccount($this->host, $this->user, $this->apikey));
        if (!is_string($ct) && !is_null($ct)) {
            $this->throwException(
                'InvalidArgumentType',
                __METHOD__,
                'string or null',
                gettype($ct)
            );
        }
        $r = array(
            "X-api-username: {$this->user}",
            "X-apikey: {$this->apikey}"
        );
        if ($ct === 'json') { $r = array_merge($r, array(
            'Content-Type: application/json',
            'Accept: application/json'
        )); }
        return $r;
    }

    /**
     * Generates the proper API URL from the given parameters
     *
     * This method will take a hash table of parameters and values and craft
     * the right API URL to call to perform an action. The 'resource' key
     * indicates which API resource is the target. $q should be key -> value array
     * matching the required query parameters.
     *
     * Note that Amara's API takes POSTed data as JSON. Some queries may
     * work sending that data in query arguments, but some don't
     * (e.g. POSTing a new user).
     *
     * In principle $q would only contain meta filters like limit or offset.
     *
     * Note that $r['resource'] doesn't match necessarily the name of
     * the resource, e.g. activities -> activity
     *
     * @TODO Validate arguments
     * @TODO Validate outputs
     * @TODO include all resources
     *
     * @since 0.1.0
     */
    function getResourceUrl(array $r, $q = array()) {
        foreach($r as $key=>$value) {
            $r[$key] = urlencode($value);
        }
        $url = '';
        switch ($r['resource']) {
            case 'activities':
                $url = "{$this->host}activity/";
                break;
            case 'activity':
                $url = "{$this->host}activity/{$r['activity_id']}/";
                break;
            case 'videos':
                $url = "{$this->host}videos/";
                break;
            case 'video':
                $url = "{$this->host}videos/{$r['video_id']}/";
                break;
            case 'languages':
                $url = "{$this->host}videos/{$r['video_id']}/languages/";
                break;
            case 'language':
                $url = "{$this->host}videos/{$r['video_id']}/languages/{$r['language']}/";
                break;
            case 'subtitles':
                $url = "{$this->host}videos/{$r['video_id']}/languages/{$r['language']}/subtitles/";
                break;
            case 'tasks':
                $url = "{$this->host}teams/{$r['team']}/tasks/";
                break;
            case 'task':
                $url = "{$this->host}teams/{$r['team']}/tasks/{$r['task_id']}/";
                break;
            case 'members':
                $url = "{$this->host}teams/{$r['team']}/members/";
                break;
            case 'safe-members':
                $url = "{$this->host}teams/{$r['team']}/safe-members/";
                break;
            case 'member':
                $url = "{$this->host}teams/{$r['team']}/members/{$r['username']}/";
                break;
            case 'users':
                $url = "{$this->host}users/{$r['username']}/";
                break;
            default:
                return null;
        }
        if (isset($q) && !empty($q)) {
            $url .= '?' . http_build_query($q);
        }
        return $url;
    }

    /**
     * cURL request
     *
     * Perform all HTTP methods.
     *
     * @since 0.1.0
     */
    protected function curl($mode, $header, $url, $data = '') {
        $cr = curl_init();
        curl_setopt($cr, CURLOPT_URL, $url);
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cr, CURLOPT_VERBOSE, $this->verbose_curl);
        curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cr, CURLOPT_HTTPHEADER, $header);
        switch ($mode) {
            case 'GET':
                break;
            case 'POST':
            case 'PUT':
                curl_setopt($cr, CURLOPT_CUSTOMREQUEST, $mode);
                curl_setopt($cr, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
            	curl_setopt($cr, CURLOPT_CUSTOMREQUEST, "DELETE");
            	break;
            default:
                return null;
        }
        $result = $this->curlTry($cr);
        curl_close($cr);
        // TODO: log non GET responses here.
        return $result;
    }

   /**
    * cURL retry loop
    *
    * Exhaust all retries for HTTP actions.
    * This is the method you'd really want to mock for tests.
    *
    * @since 0.1.0
    * @todo Ensure the way to check for error is correct
    */
    protected function curlTry($cr) {
        $retries = 0;
        do {
            $result = curl_exec($cr);
            $retries++; if ($retries > $this->retries) { return null; }
        } while($result === false);
        return $result;
    }

    /**
     * Fetch all required data from a resource
     *
     * Some Amara resources can be traversed specifying an offset and limit.
     * useResource is meant to handle that, aggregating all the responses.
     *
     * All traversable objects (so far) return the data as an array of objects
     * in $response->objects.
     *
     * If the response is not valid JSON, it's returned as-is (e.g. a subtitle
     * track); if it's JSON, but doesn't have an objects array, we won't loop to
     * fetch more data.
     *
     * Although useResource could be called directly, there's a separate
     * method for each HTTP method in case you would want to modify
     * the behavior of certain HTTP methods later on, without having to
     * duplicate the rest of useResource.
     */
    protected function useResource($method, array $r, $q = null, $data = null) {
        $result = array();
        $header = $this->getHeader(isset($r['content_type']) ? $r['content_type'] : null);
        if (!isset($q['offset'])) { $q['offset'] = 0; }
        if (isset($data) && $r['content_type'] === 'json') { $data = json_encode($data); }
        do {
            $url = $this->getResourceUrl($r, $q);
            $response = $this->curl($method, $header, $url, $data);
            $result_chunk = json_decode($response);
            if (json_last_error() != JSON_ERROR_NONE) { return $response; } // It's not JSON, just deliver as-is.
            if ($method !== 'GET' || !isset($result_chunk->objects)) { return $result_chunk; } // We can't loop this, deliver JSON.
            if (!is_array($result_chunk->objects)) { throw new \UnexpectedValueException('Traversable resource\'s \'objects\' property is not an array'); }
            // We have to loop -- merge and offset
            $result = array_merge($result, $result_chunk->objects);
            if ($result_chunk->meta->next === null) { break; }
            if (isset($q['limit'])) { $q['offset'] += $this->limit; } // TODO: check this logic
        } while($result_chunk->meta->next + $q['offset'] < $result_chunk->meta->total_count);
        return $result;
    }

    protected function getResource(array $r, $q = null, $data = null) {
        return $this->useResource('GET', $r, $q, $data);
    }

    protected function createResource(array $r, $q = null, $data = null) {
        return $this->useResource('POST', $r, $q, $data);
    }

    protected function setResource(array $r, $q = null, $data = null) {
        return $this->useResource('PUT', $r, $q, $data);
    }

    protected function deleteResource(array $r, $q = null, $data = null) {
        return $this->useResource('DELETE', $r, $q, $data);
    }

    // VIDEO LANGUAGE RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#video-language-resource

    /**
     * Listing video languages
     *
     * @since 0.4.0
     */
    function getVideoLanguages(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'languages',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
       );
        return $this->getResource($res);
    }


    /**
     * Get information about a subtitle track in the specified language
     *
     * Notice the elements required in the resource definition:
     *
     * 'resource' - the main type of resource
     * 'content_type' - the type of content expected (usually json)
     * other resource slugs as seen in the resource URL, e.g. language
     * not to confuse them with the query parameters themselves
     *
     * @since 0.4.0
     */
    function getVideoLanguage(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'language',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'language' => $r['language_code']
       );
        return $this->getResource($res);
    }

    /**
     * Get the last version number for a language
     *
     * Note that the versions array starts at 0, but
     * the version numbering starts at 1.
     *
     * Versions are in reverse order, versions[0]
     * is always the latest one. Because versions can be
     * deleted, the version_no can't be used in any way
     * as index of the versions array.
     *
     * @since 0.1.0
     */
    function getLastVersion($lang_info) {
        if (!is_object($lang_info)) {
            $this->throwException(
                'InvalidArgumentType',
                __METHOD__,
                'object',
                gettype($lang_info)
            );
        }
        if (isset($lang_info->versions[0]->version_no)) {
            return $lang_info->versions[0]->version_no;
        } else {
            return null;
        }
    }

    // VIDEO RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#video-resource

    /**
     * Get information about all videos in a team/project
     *
     * Note that this can take a long time on teams/projects
     * with many videos. Capped by $this->total_limit.
     *
     * Use $params['offset'] and your own loop
     * if you'd rather not wait for this method to finish.
     *
     * @since 0.1.0
    */
    function getVideos(array $r) {
        $res = array(
            'resource' => 'videos',
            'content_type' => 'json',
       );
        $query = array(
            'team' => isset($r['team']) ? $r['team'] : null,
            'project' => isset($r['project']) ? $r['project'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Retrieve metadata info about a video
     *
     * The same info can be retrieved by video id or by video url,
     * since each video url is associated to a unique video id.
     *
     * @since 0.1.0
     */
    function getVideoInfo(array $r) {
        $query = array();
        if (isset($r['video_id'])) {
            if (!$this->isValidVideoID($r['video_id'])) { return null; }
            $res = array(
                'resource' => 'video',
                'content_type' => 'json',
                'video_id' => $r['video_id']
           );
        } elseif (isset($r['video_url']) && $r['video_url'] !== null) {
            $res = array(
                'resource' => 'videos',
                'content_type' => 'json'
           );
            $query = array(
                'video_url' => isset($r['video_url']) ? $r['video_url'] : null
           );
        }
        return $this->getResource($res, $query);
    }

    /**
     * Move a video into a different team/project
     *
     * http://amara.readthedocs.org/en/latest/api.html#moving-videos-between-teams-and-projects
     *
     * @since 0.1.0
     */
    function moveVideo(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'video',
            'content_type' => 'json',
            'video_id' => $r['video_id']
       );
        $query = array(
            'team' => $r['team '],
            'project' => $r['project']
       );
        return $this->setResource($res, $query);
    }

    // ACTIVITY RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#activity-resource

    /**
     * Retrieve a set of activity data
     *
     * Make sure you specify either $team or $video_id
     * otherwise you'll query public activity from the whole site, which is
     * a heavy request.
     * See http://amara.readthedocs.org/en/latest/api.html#activity-resource
     *
     * @since 0.1.0
     */
    function getActivities(array $r = array()) {
        $res = array(
            'resource' => 'activities',
            'content_type' => 'json'
       );
        $query = array(
            'team' => isset($r['team']) ? $r['team'] : null,
            'video' => isset($r['video_id']) ? $r['video_id'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'language' => isset($r['language']) ? $r['language'] : null,
            'before' => isset($r['before']) ? $r['before'] : null,
            'after' => isset($r['after']) ? $r['after'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Retrieve a singe activity record
     *
     * @since 0.1.0
     */
    function getActivity(array $r) {
        $res = array(
            'resource' => 'activity',
            'content_type' => 'json',
            'activity_id' => $r['activity_id']
       );
        return $this->getResource($res);
    }

    // TASK RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#task-resource

    /**
     * Retrieve a set of task records
     *
     * @since 0.1.0
     */
    function getTasks(array $r) {
        $res = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $r['team'],
       );
        $query = array(
            'video_id' => isset($r['video_id']) ? $r['video_id'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'assignee' => isset($r['assignee']) ? $r['assignee'] : null,
            'priority' => isset($r['priority']) ? $r['priority'] : null,
            'order_by' => isset($r['order_by']) ? $r['order_by'] : null,
            'completed' => isset($r['completed']) ? $r['completed'] : null,
            'completed_before' => isset($r['completed_before']) ? $r['completed_before'] : null,
            'open' => isset($r['open']) ? $r['open'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Retrieve a singe task record
     *
     * @since 0.1.0
     */
    function getTaskInfo(array $r) {
        $r = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $r['team'],
            'task_id' => $r['task_id']
       );
        return $this->getResource($res);
    }

    /**
     * Create a new task
     *
     * You can pass the data from getVideoLanguage if you
     * retrieved it earlier, so this doesn't make a new
     * request.
     *
     * @since 0.1.0
     * @todo Log notice on invalid lang_info
     */
    function createTask(array $r, &$lang_info = null) {
        if (!is_object($lang_info)) { $lang_info = null; }
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!in_array($r['type'], array('Subtitle', 'Translate', 'Review', 'Approve'))) { return null; }
        if (!isset($r['version_no']) && in_array($r['type'], array('Review', 'Approve'))) {
            if ($lang_info === null) { $lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code'])); }
            $r['version_no'] = $this->getLastVersion($lang_info);
        }
        // TODO: It shouldn't assign the task to me
        $res = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
            'video_id' => isset($r['video_id']) ? $r['video_id'] : null,
            'language' => isset($r['language_code']) ? $r['language_code'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'assignee' => isset($r['assignee']) ? $r['assignee'] : null,
            'priority' => isset($r['priority']) ? $r['priority'] : null,
            'completed' => isset($r['completed']) ? $r['completed'] : null,
            'approved' => isset($r['approved']) ? $r['approved'] : null,
            'version_no' => isset($r['version_no']) ? $r['version_no'] : null
       );
        return $this->createResource($res, $query);
    }

    /**
     * Delete a task
     *
     * @since 0.1.0
     */
    function deleteTask($r) {
        $res = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $r['team'],
            'task_id' => $r['task_id']
       );
        return $this->deleteResource($res);
    }

    // SUBTITLES RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#subtitles-resource

    /**
     * Fetch the subtitle track
     *
     * Specifying the version is needed to retrieve unpublished subtitles
     * You may pass $lang_info if you retrieved it previously, or any
     * variable to store it afterwards
     *
     * If you don't specify the format, you'll get Amara's internal
     * subtitle object. You can use it in your code instead of
     * passing one of the formats through a parser.
     *
     * @since 0.1.0
     */
    function getSubtitle($r, &$lang_info = null) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!isset($r['version'])) {
            if ($lang_info === null) {
                $lang_info = $this->getVideoLanguage(array(
                    'video_id' => $r['video_id'],
                    'language_code' => $r['language_code']
               ));
            }
            $r['version'] = $this->getLastVersion($lang_info);
        }
        if ($r['version'] === null) { return null; }
        $res = array(
            'resource' => 'subtitles',
            'video_id' => $r['video_id'],
            'language' => $r['language_code'],
       );
        $query = array(
            'format' => isset($r['format']) ? $r['format'] : 0,
            'version' => isset($r['version']) ? $r['version'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Upload a subtitle track
     *
     * In theory this should be a createResource action,
     * but currently it works with PUT rather than POST
     *
     * You may want to fetch first and preserve here the
     * subtitles_complete/is_complete status.
     *
     * Note that sub_format defaults to SRT.
     *
     * @since 0.1.0
     */
    function uploadSubtitle($r, &$lang_info = null) {
        // Create the language if it doesn't exist
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!$lang_info && !$lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code']))) {
            $res = array(
                'resource' => 'languages',
                'content_type' => 'json',
                'video_id' => $r['video_id']
           );
            $query = array(
                'language_code' => $r['language_code']
           );
            $this->createResource($res, $query);
            $lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code']));
        }
        $res = array(
            'resource' => 'subtitles',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'language' => $r['language_code'],
       );
        $query = array();
        $data = array(
            'subtitles' => isset($r['subtitles']) ? $r['subtitles'] : null,
            'sub_format' => isset($r['sub_format']) ? $r['sub_format'] : null,
            'title' => isset($r['title']) ? $r['title'] : $lang_info->title,
            'description' => isset($r['description']) ? $r['description'] : $lang_info->description,
            'is_complete' => isset($r['complete']) ? $r['complete'] : null
       );
        return $this->setResource($res, $query, $data);
    }

    // TEAM MEMBER RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#team-member-resource

    /**
     * Get the list of members in a team
     *
     * @since 0.2.0
     */
    function getMembers($r) {
        $res = array(
            'resource' => 'members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Add a new partner member to a partner team
     *
     * This is the "unsafe" method that allows to transfer
     * users directly between "partner" teams without notifying/inviting them.
     *
     * It won't work if the user is not in a "partner" team,
     * or the destination teams isn't set as a "partner" team.
     * This is configured by Amara's admins.
     *
     * @since 0.2.0
     */
    function addPartnerMember($r) {
        $res = array(
            'resource' => 'members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
       );
        $data = array(
            'username' => $r['username'],
            'role' => $r['role']
       );
        return $this->createResource($res, $query, $data);
    }

    /**
     * Invite a user to a team
     *
     * This is the safe method. It will send the user an invitation
     * to join the team, which the user can refuse.
     *
     * @since 0.2.0
     */
    function addMember($r) {
        $res = array(
            'resource' => 'safe-members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array();
        $data = array(
            'username' => $r['username'],
            'role' => $r['role']
       );
        return $this->createResource($res, $query, $data);
    }

    /**
     * Remove a member from a team
     *
     * @since 0.2.0
     */
    function deleteMember($r) {
        $res = array(
            'resource' => 'member',
            'content_type' => 'json',
            'team' => $r['team'],
            'username' => $r['username']
       );
        return $this->deleteResource($res);
    }

    // USER RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#user-resource

    /**
     * Get user detail
     *
     * @since 0.2.0
    */
    function getUser($r) {
        $res = array(
            'resource' => 'users',
            'content_type' => 'json',
            'username' => $r['username']
       );
        return $this->getResource($res);
    }

    /**
     * Returns an array of user objects for the given list of users
     *
     * @since 0.3.0
     */
    function getUsers($users) {
        if (!is_array($users) || empty($users)) { return null; }
        $result = array();
        for ($i = 0; $i < count($users); $i++) {
            $res = array(
                'resource' => 'users',
                'content_type' => 'json',
                'username' => $users[$i]
           );
            $user = $this->getResource($res);
            if (!is_object($user)) {
                // TODO: Handle/Log error.
                continue;
            }
            $result[$i] = $user;
        }
        return $result;
    }

    // VALIDATIONTa

    /**
     * Validate API keys
     *
     * Note that we wouldn't want to perform requests to validate the account
     * until we have some assurance the host is an Amara install,
     * otherwise you could leak the credentials to somewhere unexpected.
     *
     * @todo Validate URL
     * @since 0.1.0
     */
    function validateAccount($host, $user, $apikey) {
        if (strlen($apikey) !== 40) {
            throw new \LengthException('The API key is not 40 characters long');
        } elseif (preg_match('/^[0-9a-f]*$/', $apikey) !== 1) {
            throw new \InvalidArgumentException('The API key should contain lowercase hexadecimal characters only');
        }
        return true;
    }

    /**
     * Validate an username
     *
     * This method will eventually support using a cached userlist
     *
     * @since 0.2.0
     */
    function isValidUser($r, $use_cache = null) {
        return $this->getUser(array('username' => $r));
    }


    /**
     * Check if an object has the expected methods
     *
     * @since 0.1.0
     */
    function isValidObject($object, $valid_methods) {
        if (!is_object($object) || !is_array($valid_methods)) { return null; }
        $obj_methods = get_class_methods($object);
        if (count(array_intersect($valid_methods, $obj_methods)) === count($valid_methods)) {
            return true;
        }
        return false;
    }

    /**
     * Check if a string looks like a valid video ID
     *
     * @since 0.1.0
     */
    function isValidVideoID($video_id) {
        if (strlen($video_id) !== 12) {
            return false;
        } elseif (preg_match('/^[A-Za-z0-9]*$/', $video_id) !== 1) {
            return false;
        }
        return true;
    }

    /**
     * Check if a variable is a valid role string
     *
     * @since 0.2.0
     */
    function isValidRole($role) {
        if (!isset($role) || !is_string($role)) { return false; }
        return in_array($role, array('admin', 'manager', 'owner', 'contributor'));
    }

    /**
     * Check if a task name is valid
     */
    function isValidTaskName($taskName) {
        if (!isset($taskName)) { return false; }
        return in_array($taskName, array('Subtitle', 'Translate', 'Review', 'Approve'));
    }



    /**
     * Check if a string is a valid language codec
     *
     * @since 0.3.0
     * @TODO Optionally fetch a list of language codes
     */
    function isValidLanguageCode($languageCode) {
	    $amaraLanguages = array("aa", "ab", "ae", "af", "aka", "amh", "an", "arc", "ar", "arq", "ase", "as", "ast", "av", "ay", "az", "bam", "ba", "be", "ber", "bg", "bh", "bi", "bn", "bnt", "bo", "br", "bs", "bug", "cak", "ca", "ceb", "ce", "ch", "cho", "cku", "co", "cr", "cs", "ctd", "ctu", "cu", "cu", "cv", "cy", "da", "de", "dv", "dz", "ee", "efi", "el", "en-gb", "en", "eo", "es-ar", "es-mx", "es", "es-ni", "et", "eu", "fa", "ff", "fil", "fi", "fj", "fo", "fr-ca", "fr", "fy", "fy-nl", "ga", "gd", "gl", "gn", "gu", "gv", "hai", "hau", "haw", "haz", "hus", "hb", "hch", "he", "hi", "ho", "hr", "ht", "hu", "hup", "hy", "hz", "ia", "ibo", "id", "ie", "ig", "ii", "ik", "ilo", "inh", "io", "iro", "is", "it", "iu", "ja", "jv", "ka", "kar", "kau", "kg", "kik", "ki", "kin", "kj", "kk", "kl", "km", "kn", "ko", "kon", "kr", "ksh", "ks", "ku", "kv", "kw", "ky", "la", "lb", "lg", "lg", "li", "lin", "lkt", "lld", "ln", "lo", "lt", "ltg", "lu", "lua", "luo", "luy", "lv", "mad", "meta-audio", "meta-geo", "meta-tw", "meta-wiki", "mg", "mh", "mi", "mk", "ml", "mlg", "mo", "moh", "mn", "mni", "mnk", "mos", "mr", "ms", "mt", "mus", "my", "na", "nan", "nb", "nci", "nd", "ne", "ng", "nl", "nn", "no", "nr", "nso", "nv", "ny", "oc", "oji", "om", "or", "orm", "os", "pa", "pam", "pan", "pap", "pi", "pl", "pnb", "prs", "ps", "pt-br", "pt", "que", "qvi", "raj", "rm", "rn", "ro", "ru", "run", "rup", "ry", "rw", "sa", "sc", "sco", "sd", "se", "sg", "sgn", "sh", "si", "sk", "skx", "sl", "sm", "sna", "sot", "sa", "sq", "sr-latn", "sr", "srp", "ss", "st", "su", "sv", "swa", "szl", "ta", "tar", "te", "tet", "tg", "th", "tir", "tk", "tl", "tlh", "tn", "to", "toj", "tr", "ts", "tsn", "tsz", "tt", "tw", "ty", "tzh", "tzo", "ug", "uk", "umb", "ur", "uz", "ve", "vi", "vls", "vo", "wa", "wbl", "wol", "xho", "yaq", "yi", "yor", "yua", "za", "zam", "zh-cn", "zh-hk", "zh", "zh-sg", "zh-tw", "zul");
	    return in_array($languageCode, $amaraLanguages);
    }

    /**
     * Exception messages
     *
     * @since 0.4.1
     */
    protected function throwException($type, $caller, $expected, $argument) {
        switch ($type) {
            case "InvalidArgumentType":
                $message = "Argument passed to {$caller} must be of the type {$expected}, " . gettype($argument) . ' given.';
                throw new \InvalidArgumentException($message);
                break;
            case "InvalidAPIAccount":
                $message = "Invalid API account settings passed to {$caller}, Expected: {$expected}, Got: {$got}";
                throw new \InvalidArgumentException($message);
                break;
            default:
                $message = "Unknown exception. Caller: {$caller}, Expected: {$expected}, Got: {$got}";
                throw new \UnknownException($message);
                break;
        }

    }

}

/**
 * Dummy PSR-3 logger
 *
 * Used as dummy in case no valid PSR-3 logger was supplied,
 * so we don't get E_FATALs when we try to log something.
 *
 * Currently unused -- logging points will be added soon.
 *
 * @used-by AmaraAPI
 */
class DummyLogger {
    function emergency($message, array $context = array()) {}
    function alert($message, array $context = array()) {}
    function critical($message, array $context = array()) {}
    function error($message, array $context = array()) {}
    function warning($message, array $context = array()) {}
    function notice($message, array $context = array()) {}
    function info($message, array $context = array()) {}
    function debug($message, array $context = array()) {}
    function log($level, $message, array $context = array()) {}
}
