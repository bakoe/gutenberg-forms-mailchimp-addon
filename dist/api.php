<?php 


class cwp_gf_addon_MailChimp {

    const proxy = 'https://us20.api.mailchimp.com/3.0/';

    const plugin_option_slug = 'cwp_gf_integrations_mailchimp';
    const options = array(
        'api_key' 
    );

    public function __construct() {

        $this->api_key = get_option('cwp__mailchimp__api_key');

    }
    
    public function get_lists() {


        $connection_uri = self::proxy . 'lists/';

        $response =  $this->fetch_list( $this->api_key );
        
        
        if (gettype( $response ) !== 'string') return []; // this means that fetched failed

        $data = json_decode($response);

        $lists_names = array();

        if (!empty($data) and property_exists($data, 'lists')) {

            foreach ($data->lists as $key => $list ) {

                $list_data = array(
                    'name' => $list->name,
                    'value' => $list->id
                );

                $lists_names[] = $list_data;

            }

        }


        return $lists_names;

    }

    public function fetch_list( $api_key ) {
        
        $dc = substr($api_key,strpos($api_key,'-')+1); // dataCenter, it is the part of your api key - us5, us8 etc
        $args = array(
            'headers' => array(
               'Authorization' => 'Basic ' . base64_encode( 'user:'. $api_key )
           )
       );
       $response = wp_remote_get( 'https://'.$dc.'.api.mailchimp.com/3.0/lists/', $args );
       $response_body = wp_remote_retrieve_body( $response );
       $code = wp_remote_retrieve_response_code( $response );

       if ($code === 200) {
         return $response_body;
       } 


        return [];
    }

    public function add_subscriber( $entry ) {
       
       
       
        $apiKey = $this->api_key;
        $listId = $entry['list'];
        
        $memberId = md5(strtolower($entry['EMAIL']));
        $dataCenter = substr($apiKey,strpos($apiKey,'-')+1);
        $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . $listId . '/members/' . $memberId;
        
        $tags = [];

        $address = array(
            'addr1' => $this->get_value($entry, 'ADDRESS_1'),
            'zip'   => $this->get_value($entry, 'ZIP'),
            'country' => $this->get_value($entry, 'COUNTRY'),
            'city'    => $this->get_value($entry, 'CITY'),
            'state'   => $this->get_value($entry, 'STATE')
        );

        $merge_fields = [];

        if (!$this->is_address_null($address)) {
        
            $merge_fields['ADDRESS'] = $address;
        }

        if ($this->has_field($entry, 'FNAME')) {
            $merge_fields['FNAME'] = $entry['FNAME'];
        }

        if ($this->has_field($entry, 'LNAME')) {
            $merge_fields['LNAME'] = $entry['LNAME'];
        }

        if ($this->has_field($entry, 'PHONE')) {
            $merge_fields['PHONE'] = $entry['PHONE'];
        }

        if ( array_key_exists('tags', $entry) ) {

            /**
             * As opposed to the previously used 'tags' field type, a simple 'text' field type is used for the tags,
             * resulting in the need to programatically split the entered string into the tags the user wanted to enter.
             *
             * The 'tags' field is expected to be of the format 'Tag name A, Tag Name B\nTag Name C'
             * @see https://stackoverflow.com/a/13225184
             * @see https://stackoverflow.com/questions/13225118/in-php-how-can-i-split-a-string-by-whitespace-commas-and-newlines-at-the-same#comment57332923_13225184
             */
            // Split the string on newline (\n) and comma (\,) characters, avoiding empty string via PREG_SPLIT_NO_EMPTY
            $split_tags = preg_split('/[\n\,]+/', $entry['tags'], -1, PREG_SPLIT_NO_EMPTY);
            // Trim the individual tags to remove any trailing or leading whitespace
            $split_tags = array_map(function($tag) { return trim($tag); }, $split_tags);

            if (count($split_tags) !== 0) {

                $tags = $split_tags;

            }

        }

        $doubleOptIn = $entry['double_opt_in'];
        $status = 'subscribed';
        if ( !is_null( $doubleOptIn ) && $doubleOptIn == 1 ) {
            // Check if the subscriber has already been added before
            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( 'user:' . $apiKey )
                ),
            );
            $response = wp_remote_get( $url, $args );
            $code = wp_remote_retrieve_response_code( $response );
            if ($code === 404) {
                // There exists no subscriber yet (or only a 'pending' one) with the given mail – thus, add as 'pending'
                $status = 'pending';
            }
            // Else, leave the status at 'subscribed' (because there already exists a subscriber with the given mail)
        }

        $json = json_encode([
            'email_address' => $entry['EMAIL'],
            'status'        => $status, // "subscribed","unsubscribed","cleaned","pending"
            'merge_fields'  => $merge_fields,
            'tags'          => $tags
        ]);

        $args = array(
            'method'  => 'PUT',
            'body'    => $json,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( 'user:' . $apiKey )
            ),
            'httpversion' => '1.0',
            'timeout' => 45,
            'blocking' => true,
        );


        $response = wp_remote_post( $url, $args );
        $response_body = wp_remote_retrieve_body( $response );

        return true;
    }
    public function is_address_null($address) {

        $res = true;

        foreach( $address as $key => $value  ) {

            if ($value !== 'null') {
                $res = false;
            }

        }

        return $res;


    }

    public function get_value($data , $value) {
        
        

        if (array_key_exists( $value , $data)) {
            
            $v = $data[$value];

            if ($v === '' || is_null($v)) {
                return 'null';
            } else {
                return $v;
            }
        } else {
            return 'null';
        }
        
       
    }

    public function has_field($submission, $field) {

        if (array_key_exists($field,$submission) and $submission[$field] !== '' and !is_null($submission[$field] !== '')) {

            return true; 

        } else { 
            return false;
         }

    }

    public function post( $submission ) {


        $enabled = get_option('cwp__enable__mailchimp') === '1' ? true : false;
                
        if ($enabled and array_key_exists('list', $submission ) and  array_key_exists('EMAIL', $submission )  ) {
            $this->add_subscriber($submission);
        }


    }


}