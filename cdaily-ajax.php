<?php
/**
  * Copyright 2013-2014, MH Software, Inc.
  *
  * This program is free software; you can redistribute it and/or
  * modify it under the terms of the GNU General Public License
  * as published by the Free Software Foundation; either version 2
  * of the License, or (at your option) any later version.
  * This program is distributed in the hope that it will be useful,
  * but WITHOUT ANY WARRANTY; without even the implied warranty of
  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  * GNU General Public License for more details.
  * You should have received a copy of the GNU General Public License
  * along with this program; if not, write to the Free Software
  * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/



/**
 * This file provides the WordPress AJAX interface for 
 * retrieving data. It's the wedge between the various 
 * widgets that make Ajax calls, and the Connect Daily back-end.
 *  
 */
class CDWPAjaxHandler {

    private $plugin = null;

    public function __construct($plugin_parent) {
        $this->plugin = $plugin_parent;
        $this->registerFunctions();
    }

    /**
     * Retrieve the day map of 1/0's that list which days of a 
     * period have events.
     */
    public function dayMap() {

        $start = $_GET['date'];
        $by_id = $_GET['by_id'];
        $by_method = $_GET['by_method'];
        $json_url = 'jsonp/' . $by_method . '/daymap/' . $by_id . '.js';
        $reqData = $this->plugin->getPostData('daymap', $json_url, array('start' => $start));
        header('Content-Type: application/json; charset=utf-8');
        echo $_GET['callback'] . '(' . $reqData->content . ');';
        wp_die();
    }


    /**
     * This is a callback for retrieving the next or previous 
     * full-month responsive calendar. 
     */
    public function displayCalendar() {
        $cal = new CDCalendarWriter($this->plugin);
        echo $cal->renderMonth($_GET, true);
        wp_die();
    }




    /**
     * This function retrieves the events for one day. It does the 
     * JSON call, and then uses the code in detailed-list.php to 
     * actually format the data. It's then returned to the 
     * Javascript caller, where it handles the pop-dialog. 
     */
    public function displayDay() {
        if (isset($_GET['function'])) {
            echo $this->get_day_map();
            wp_die();
            return;
        }
        $writer = new CDCalendarWriter($this->plugin);
        echo $writer->renderSpecificDay($_GET);
        wp_die();
    }


    /**
     * Implement Single-Signon.
     */
    public function doSignon() {
        $user = wp_get_current_user();
        if ($user == null) {
            // The user must be logged in for this call.
            $this->plugin->logError('call to CDailyWPAjax->doSignon() when user not logged in.');
            wp_die();
            return;
        }
        $ssoHelper = new CDailySSO($this->plugin);
        try {
            $data = $ssoHelper->processClientSSORequest();
        } catch (CDailySSOException $ssoe) {
            $data = $ssoHelper->getStandardLogin();
        } catch (Exception $ex) {
            // This shouldn't happen, but if there's some error, let's try to recover.
            $data = $ssoHelper->getStandardLogin();
        }
        
        if (isset($_GET['callback'])) {
            $data=$_GET['callback'].'('.$data.');';
        } 
        echo $data;
        
        wp_die();
    }

    private function get_day_map() {
        $start = $_GET['date'];
        $by_method = $_GET['by_method'];
        $by_id = $_GET['by_id'];
        $json_url = "jsonp/$by_method/daymap/$by_id.js";
        $reqData = $this->plugin->getPostData('daymap', $json_url,array('start' => $start));
        if (!$reqData->wasSuccess()) {
            $reqData->content = '{}';
        }
        return $_GET['callback'] . '(' . $reqData->content . ');';
    }

    /** 
      * This function retrieves an iCalendar file from Connect 
      * Daily, and caches it. 
      *  
      * It tries to handle last-modified-since from the client, to 
      * keep them from requesting a lot of data. However, the 
      * WordPress transient API keeps us from effectively making 
      * if-modified-since requests back to Connect Daily. 
      *  
      * So, to make a long story short, the worst-case scenario is that 
      * Connect Daily will take a hit every CACHE_PERIOD_SECONDS, but 
      * If the Connect Daily data isn't modified, then WordPress sends a 
      * 304. This will cut down on the bandwidth usage from them. 
      *  
      */
    public function iCalendar() {

        $if_modified = -1;

        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $datetime = DateTime::createFromFormat(DateTime::RFC2822, $_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($datetime instanceof DateTime) {
                $if_modified = $datetime->getTimestamp();
            }
        }

        $by_id = $_GET['by_id'];
        $by_method = $_GET['by_method'];
        $ical_url = 'iCal/' . $by_method . '/' . $by_id . '.ics';

        $reqData = $this->plugin->getPostData('cdaily-icalendar', $ical_url,array('nodefaultcontact' => '1'));

        if ($reqData->last_modified <= $if_modified && $if_modified > 0) {
            // Client sent us a if-modified-since header AND
            // Client's cache IS current, so we just respond '304 Not Modified'.
            header('Last-Modified: ' . CDDateTime::toRFC2822($if_modified), true, 304);
        } else {

            $gzip = extension_loaded("zlib");
            if ($gzip) {
                ob_start("ob_gzhandler");
            }

            header('Content-Type: text/calendar; charset=utf-8');
            header('Last-Modified: ' . CDDateTime::toRFC2822($reqData->last_modified));
            echo $reqData->content;
            if ($gzip) {
                ob_end_flush();
            }
        }
        wp_die();
    }

    /**
     * These are the functions that are registered through the 
     * admin-ajax.php page. For a reference, look at the WordPress 
     * Codex topic on AJAX. 
     *  
     * Strangely, you hvae to register both privileged and 
     * non-privileged versions for things to work for logged 
     * in/logged out. 
    */
    private function registerFunctions() {
        add_action('wp_ajax_cd_daymap', array($this, 'dayMap'));
        add_action('wp_ajax_nopriv_cd_daymap', array($this, 'dayMap'));

        add_action('wp_ajax_cd_viewitem', array($this, 'viewItem'));
        add_action('wp_ajax_nopriv_cd_viewitem', array($this, 'viewItem'));

        add_action('wp_ajax_cd_displayday', array($this, 'displayDay'));
        add_action('wp_ajax_nopriv_cd_displayday', array($this, 'displayDay'));

        add_action('wp_ajax_cd_icalendar', array($this, 'iCalendar'));
        add_action('wp_ajax_nopriv_cd_icalendar', array($this, 'iCalendar'));

        add_action('wp_ajax_cd_calendar', array($this, 'displayCalendar'));
        add_action('wp_ajax_nopriv_cd_calendar', array($this, 'displayCalendar'));

        add_action('wp_ajax_cd_sso', array($this, 'doSignon'));
        add_action('wp_ajax_nopriv_cd_sso', array($this, 'doSignon'));

        add_action('wp_ajax_cd_csshelper', array($this, 'returnStyleCSS'));
        add_action('wp_ajax_nopriv_cd_csshelper', array($this, 'returnStyleCSS'));

        add_action('wp_ajax_cd_dismisshint', array($this, 'dismissHint'));
    }

    public function dismissHint(){
        $hintName = $_GET['hintName'];
        $this->plugin->markHintSeen($hintName);
        echo $_GET['callback'] . '({});';

        wp_die();
    }

    /**
     * Return Per-Item CSS styles.
     */
    public function returnStyleCSS(){
        $cal = new CDCalendarWriter($this->plugin);
        $dt=new DateTime("now",new DateTimeZone("UTC"));
        $dt=$dt->add(new DateInterval('PT30M'));
        header('Content-Type: text/css; charset=utf-8',true);
        header('Expires: '.$dt->format(DateTime::RFC1123),true);
        header('Cache-Control: max-age=1800',true);
        echo $cal->renderStyleCSS();
        wp_die();
    }

    /**
     * This function retrieves the content for one event. It does 
     * the JSON call, and then uses the code in detailed-list.php to
     * actually format the data. It's then returned to the 
     * Javascript caller, where it handles the pop-dialog. 
     */
    public function viewItem() {

        $fields = array(
            'start' => $_GET['date'],
            'cal_item_id' =>  $_GET['cal_item_id']
            );

        $lister = new CDailyEventsRenderer($this->plugin);
        $lister->showEnds=true;
        echo $lister->processViewItem($fields);
        wp_die();
    }
}
