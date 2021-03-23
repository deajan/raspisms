<?php

/*
 * This file is part of RaspiSMS.
 *
 * (c) Pierre-Lin Bonnemaison <plebwebsas@gmail.com>
 *
 * This source file is subject to the GPL-3.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace controllers\publics;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

    /**
     * Controller of callback pages, like sms status update notification.
     */
    class Callback extends \descartes\Controller
    {
        private $logger;
        private $user;
        private $internal_user;
        private $internal_sended;
        private $internal_received;
        private $internal_adapter;
        private $internal_media;
        private $internal_phone;

        public function __construct()
        {
            $bdd = \descartes\Model::_connect(DATABASE_HOST, DATABASE_NAME, DATABASE_USER, DATABASE_PASSWORD);

            $this->internal_user = new \controllers\internals\User($bdd);
            $this->internal_sended = new \controllers\internals\Sended($bdd);
            $this->internal_received = new \controllers\internals\Received($bdd);
            $this->internal_media = new \controllers\internals\Media($bdd);
            $this->internal_adapter = new \controllers\internals\Adapter();
            $this->internal_phone = new \controllers\internals\Phone();

            //Logger
            $this->logger = new Logger('Callback ' . uniqid());
            $this->logger->pushHandler(new StreamHandler(PWD_LOGS . '/callback.log', Logger::DEBUG));

            //If invalid api key, quit with error
            $this->user = false;
            $api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? false;
            if ($api_key)
            {
                $this->user = $this->internal_user->get_by_api_key($api_key);
            }

            if (!$this->user)
            {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid API key. You must provide a valid GET or POST api_key param.']);
                $this->logger->error('Callback call failed with invalid api key : ' . $api_key);

                exit(1);
            }

            $this->logger->info('Callback call succed for user id : ' . $this->user['id']);
        }

        /**
         * Function call on a sended sms status change notification reception.
         * We return nothing, and we let the adapter do his things.
         *
         * @param string $adapter_uid : Uid of the adapter to use
         *
         * @return bool : true on success, false on error
         */
        public function update_sended_status(string $adapter_uid)
        {
            $this->logger->info('Callback status call with adapter uid : ' . $adapter_uid);

            //Search for an adapter
            $find_adapter = false;
            $adapters = $this->internal_adapter->list_adapters();
            foreach ($adapters as $adapter)
            {
                if (mb_strtolower($adapter['meta_uid']) === $adapter_uid)
                {
                    $find_adapter = $adapter;
                }
            }

            if (false === $find_adapter)
            {
                $this->logger->error('Callback status use non existing adapter : ' . $adapter_uid);

                return false;
            }

            //Instanciate adapter, check if status change is supported and if so call status change callback
            $adapter_classname = $find_adapter['meta_classname'];
            if (!$find_adapter['meta_support_status_change'])
            {
                $this->logger->error('Callback status use adapter ' . $adapter_uid . ' which does not support status change.');

                return false;
            }

            $callback_return = $adapter_classname::status_change_callback();
            if (!$callback_return)
            {
                $this->logger->error('Callback status with adapter ' . $adapter_uid . ' failed because adapter cannot process data with success.');

                return false;
            }

            $sended = $this->internal_sended->get_by_uid_and_adapter_for_user($this->user['id'], $callback_return['uid'], $adapter_classname);
            if (!$sended)
            {
                $this->logger->error('Callback status try update inexisting message with uid = ' . $callback_return['uid'] . '.');

                return false;
            }

            //Do not update if current status is delivered or failed
            if (\models\Sended::STATUS_DELIVERED === $sended['status'] || \models\Sended::STATUS_FAILED === $sended['status'])
            {
                $this->logger->info('Callback status update message ignore because status is already ' . $sended['status'] . '.');

                return false;
            }

            $this->logger->info('Callback status update message with uid ' . $callback_return['uid'] . ' to ' . $callback_return['status'] . '.');
            $this->internal_sended->update_status_for_user($this->user['id'], $sended['id'], $callback_return['status']);

            return true;
        }

        /**
         * Function call on sms reception notification
         * We return nothing, and we let the adapter do his things.
         *
         * @param string $adapter_uid : Uid of the adapter to use
         * @param int    $id_phone    : Phone id
         *
         * @return bool : true on success, false on error
         */
        public function reception(string $adapter_uid, int $id_phone)
        {
            $this->logger->info('Callback reception call with adapter uid : ' . $adapter_uid);

            //Search for an adapter
            $find_adapter = false;
            $adapters = $this->internal_adapter->list_adapters();
            foreach ($adapters as $adapter)
            {
                if (mb_strtolower($adapter['meta_uid']) === $adapter_uid)
                {
                    $find_adapter = $adapter;
                }
            }

            if (false === $find_adapter)
            {
                $this->logger->error('Callback reception use non existing adapter : ' . $adapter_uid);

                return false;
            }

            //Instanciate adapter, check if status change is supported and if so call status change callback
            $adapter_classname = $find_adapter['meta_classname'];
            if (!$find_adapter['meta_support_reception'])
            {
                $this->logger->error('Callback recepetion use adapter ' . $adapter_uid . ' which does not support reception.');

                return false;
            }

            $response = $adapter_classname::reception_callback();
            if ($response['error'])
            {
                $this->logger->error('Callback reception with adapter ' . $adapter_uid . ' failed : ' . $response['error_message']);

                return false;
            }

            $sms = $response['sms'];
            $mms = !empty($sms['mms']);
            $medias = empty($sms['medias']) ? [] : $sms['medias'];
            $media_ids = [];

            //We create medias to link to the sms
            if ($mms)
            {
                foreach ($medias as $media)
                {
                    try
                    {
                        $media['mimetype'] = empty($media['mimetype']) ? mime_content_type($media['filepath']) : $media['mimetype'];

                        $mimey = new \Mimey\MimeTypes;
                        $extension = empty($media['extension']) ? $mimey->getExtension($media['mimetype']) : $media['extension'];

                        $new_filename = \controllers\internals\Tool::random_uuid() . '.' . $extension;
                        $new_filedir = PWD_DATA . '/medias/' . $this->user['id'];
                        $new_filerelpath = 'medias/' . $this->user['id'] . '/' . $new_filename;
                        $new_filepath = $new_filedir . '/' . $new_filename;

                        //Create user dir if not exists 
                        if (!file_exists($new_filedir))
                        {
                            if (!mkdir($new_filedir, fileperms(PWD_DATA), true))
                            {
                                throw new \Exception('Cannot create dir ' . $new_filedir . ' to copy media : ' . json_encode($media));
                            }
                        }

                        if (!rename($media['filepath'], $new_filepath))
                        {
                            throw new \Exception('Cannot copy media : ' . json_encode($media) . ' to ' . $new_filepath);
                        }

                        $new_media_id = $this->internal_media->create($this->user['id'], $new_filerelpath);
                        if (!$new_media_id)
                        {
                            throw new \Exception('Cannot save into db media : ' . json_encode($media));
                        }

                        $media_ids[] = $new_media_id;
                    }
                    catch (\Throwable $t)
                    {
                        $this->logger->error($t->getMessage());
                        continue;
                    }
                }
            }

            $response = $this->internal_received->receive($this->user['id'], $id_phone, $sms['text'], $sms['origin'], $sms['at'], \models\Received::STATUS_UNREAD, $mms, $media_ids);
            if ($response['error'])
            {
                $this->logger->error('Failed receive message : ' . json_encode($sms) . ' with error : ' . $response['error_message']);

                return false;
            }

            $this->logger->info('Callback reception successfully received message : ' . json_encode($sms));

            return true;
        }
        
        
        /**
         * Function call on call reception notification
         * We return nothing, and we let the adapter do his things.
         *
         * @param int    $id_phone    : Phone id
         *
         * @return bool : true on success, false on error
         */
        public function inbound_call(int $id_phone)
        {
            $this->logger->info('Callback reception call with phone : ' . $id_phone);
            $phone = $this->internal_phone->get_for_user($this->user['id'], $id_phone);

            if (!$phone)
            {
                $this->logger->error('Callback inbound_call use non existing phone : ' . $id_phone);

                return false;
            }

            if (!class_exists($phone['adapter']))
            {
                $this->logger->error('Callback inbound_call use non existing adapter : ' . $phone['adapter']);

                return false;
            }

            if (!$phone['adapter']::meta_support_inbound_call_callback())
            {
                $this->logger->error('Callback inbound_call use adapter ' . $phone['adapter'] . ' which does not support inbound_call callback.');

                return false;
            }

            $response = $phone['adapter']::inbound_call_callback();
            if ($response['error'])
            {
                $this->logger->error('Callback reception with adapter ' . $adapter_uid . ' failed : ' . $response['error_message']);

                return false;
            }

            $sms = $response['sms'];
            $mms = !empty($sms['mms']);
            $medias = empty($sms['medias']) ? [] : $sms['medias'];
            $media_ids = [];

            //We create medias to link to the sms
            if ($mms)
            {
                foreach ($medias as $media)
                {
                    try
                    {
                        $media['mimetype'] = empty($media['mimetype']) ? mime_content_type($media['filepath']) : $media['mimetype'];

                        $mimey = new \Mimey\MimeTypes;
                        $extension = empty($media['extension']) ? $mimey->getExtension($media['mimetype']) : $media['extension'];

                        $new_filename = \controllers\internals\Tool::random_uuid() . '.' . $extension;
                        $new_filedir = PWD_DATA . '/medias/' . $this->user['id'];
                        $new_filerelpath = 'medias/' . $this->user['id'] . '/' . $new_filename;
                        $new_filepath = $new_filedir . '/' . $new_filename;

                        //Create user dir if not exists 
                        if (!file_exists($new_filedir))
                        {
                            if (!mkdir($new_filedir, fileperms(PWD_DATA), true))
                            {
                                throw new \Exception('Cannot create dir ' . $new_filedir . ' to copy media : ' . json_encode($media));
                            }
                        }

                        if (!rename($media['filepath'], $new_filepath))
                        {
                            throw new \Exception('Cannot copy media : ' . json_encode($media) . ' to ' . $new_filepath);
                        }

                        $new_media_id = $this->internal_media->create($this->user['id'], $new_filerelpath);
                        if (!$new_media_id)
                        {
                            throw new \Exception('Cannot save into db media : ' . json_encode($media));
                        }

                        $media_ids[] = $new_media_id;
                    }
                    catch (\Throwable $t)
                    {
                        $this->logger->error($t->getMessage());
                        continue;
                    }
                }
            }

            $response = $this->internal_received->receive($this->user['id'], $id_phone, $sms['text'], $sms['origin'], $sms['at'], \models\Received::STATUS_UNREAD, $mms, $media_ids);
            if ($response['error'])
            {
                $this->logger->error('Failed receive message : ' . json_encode($sms) . ' with error : ' . $response['error_message']);

                return false;
            }

            $this->logger->info('Callback reception successfully received message : ' . json_encode($sms));

            return true;
        }
    }
