<?php
defined('_JEXEC') or die('Restricted access');

/**
 * VirtueMart script file
 *
 * This file is executed during install/upgrade and uninstall
 *
 * @author Patrick Kohl, Max Milbers
 * @package VirtueMart
 */

defined('DS') or define('DS', DIRECTORY_SEPARATOR);

// hack to prevent defining these twice in 1.6 installation
if (! defined('_VM_SCRIPT_INCLUDED')) {

    define('_VM_SCRIPT_INCLUDED', true);

    require_once JPATH_ROOT . DS . 'administrator/components/com_virtuemart/helpers/config.php';
    require_once JPATH_ROOT . DS . 'administrator/components/com_virtuemart/helpers/vmmodel.php';
    require_once JPATH_ROOT . DS . 'administrator/components/com_virtuemart/models/paymentmethod.php';

    class com_VirtueMart_frisbee_pluginInstallerScript
    {
        public function postflight()
        {
            $this->vmInstall();
        }

        public function vmInstall()
        {
            jimport('joomla.filesystem.file');
            jimport('joomla.installer.installer');

            $this->createIndexFolder(JPATH_ROOT.DS.'plugins'.DS.'vmpayment');

            $this->path = JInstaller::getInstance()->getPath('extension_administrator');

            $elementName = 'frisbee';
            $this->updateShipperToShipment();
            $this->installPlugin('VM - Payment, Frisbee', 'plugin', $elementName, 'vmpayment');

            $paymentJPluginId = $this->getPaymentJPluginId($elementName);

            $this->createPaymentMethod($paymentJPluginId);

            // language auto move
            $src = $this->path.DS."language";
            $dst = JPATH_ADMINISTRATOR.DS."language";
            $this->recurse_copy($src, $dst);

            echo "<H3>Virtuemart Frisbee payment plugin successfully installed.</h3>";

            return true;
        }

        /**
         * Installs a vm plugin into the database
         *
         */
        private function installPlugin($name, $type, $element, $group)
        {

            $data = [];

            if (version_compare(JVERSION, '1.7.0', 'ge')) {

                // Joomla! 1.7 code here
                $table = JTable::getInstance('extension');
                $data['enabled'] = 1;
                $data['access'] = 1;
                $tableName = '#__extensions';
                $idfield = 'extension_id';
            } elseif (version_compare(JVERSION, '1.6.0', 'ge')) {

                // Joomla! 1.6 code here
                $table = JTable::getInstance('extension');
                $data['enabled'] = 1;
                $data['access'] = 1;
                $tableName = '#__extensions';
                $idfield = 'extension_id';
            } else {

                // Joomla! 1.5 code here
                $table = JTable::getInstance('plugin');
                $data['published'] = 1;
                $data['access'] = 0;
                $tableName = '#__plugins';
                $idfield = 'id';
            }

            $data['name'] = $name;
            $data['type'] = $type;
            $data['element'] = $element;
            $data['folder'] = $group;

            $data['client_id'] = 0;

            $src = $this->path.DS.'plugins'.DS.$group.DS.$element;

            if (version_compare(JVERSION, '1.6.0', 'ge')) {
                $data['manifest_cache'] = json_encode(JApplicationHelper::parseXMLInstallFile($src.DS.$element.'.xml'));
            }

            $db = JFactory::getDBO();
            $q = 'SELECT '.$idfield.' FROM `'.$tableName.'` WHERE `name` = "'.$name.'" ';
            $db->setQuery($q);
            $count = $db->loadResult();

            if (! empty($count)) {
                $table->load($count);
            }

            if (! $table->bind($data)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->bind throws error for '.$name.' '.$type.' '.$element.' '.$group);
            }

            if (! $table->check($data)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->check throws error for '.$name.' '.$type.' '.$element.' '.$group);
            }

            $storeResult = $table->store($data);
            if (! $storeResult) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->store throws error for '.$name.' '.$type.' '.$element.' '.$group);
            }

            $errors = $table->getErrors();
            foreach ($errors as $error) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(get_class($this).'::store '.$error);
            }

            if (version_compare(JVERSION, '1.7.0', 'ge')) {
                // Joomla! 1.7 code here
                $dst = JPATH_ROOT.DS.'plugins'.DS.$group.DS.$element;
            } elseif (version_compare(JVERSION, '1.6.0', 'ge')) {
                // Joomla! 1.6 code here
                $dst = JPATH_ROOT.DS.'plugins'.DS.$group.DS.$element;
            } else {
                // Joomla! 1.5 code here
                $dst = JPATH_ROOT.DS.'plugins'.DS.$group;
            }

            $this->recurse_copy($src, $dst);
        }

        public function installModule($title, $module, $ordering, $params)
        {

            $params = '';

            $table = JTable::getInstance('module');
            if (version_compare(JVERSION, '1.7.0', 'ge')) {
                // Joomla! 1.7 code here
                $data['position'] = 'position-4';
                $data['access'] = $access = 1;
            } elseif (version_compare(JVERSION, '1.6.0', 'ge')) {
                // Joomla! 1.6 code here
                $data['position'] = 'left';
                $data['access'] = $access = 1;
            } else {
                // Joomla! 1.5 code here
                $data['position'] = 'left';
                $data['access'] = $access = 0;
            }

            $src = JPATH_ROOT.DS.'modules'.DS.$module;
            if (version_compare(JVERSION, '1.6.0', 'ge')) {
                $data['manifest_cache'] = json_encode(JApplicationHelper::parseXMLInstallFile($src.DS.$module.'.xml'));
            }
            $data['title'] = $title;
            $data['ordering'] = $ordering;
            $data['published'] = 1;
            $data['module'] = $module;
            $data['params'] = $params;

            $data['client_id'] = $client_id = 0;

            $db = $table->getDBO();
            $q = 'SELECT id FROM `#__modules` WHERE `title` = "'.$title.'" ';
            $db->setQuery($q);
            $id = $db->loadResult();
            if (! empty($id)) {
                $data['id'] = $id;
            }

            if (! $table->bind($data)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->bind throws error for '.$title.' '.$module.' '.$params);
            }

            if (! $table->check($data)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->check throws error for '.$title.' '.$module.' '.$params);
            }

            if (! $table->store($data)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('VMInstaller table->store throws error for for '.$title.' '.$module.' '.$params);
            }

            $errors = $table->getErrors();
            foreach ($errors as $error) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(get_class($this).'::store '.$error);
            }

            $lastUsedId = $table->id;

            $q = 'SELECT moduleid FROM `#__modules_menu` WHERE `moduleid` = "'.$lastUsedId.'" ';
            $db->setQuery($q);
            $moduleid = $db->loadResult();

            $action = '';
            if (empty($moduleid)) {
                $q = 'INSERT INTO `#__modules_menu` (`moduleid`, `menuid`) VALUES( "'.$lastUsedId.'" , "0");';
            } else {
                $q = 'UPDATE `#__modules_menu` SET `menuid`= "0" WHERE `moduleid`= "'.$moduleid.'" ';
            }
            $db->setQuery($q);
            $db->query();

            if (version_compare(JVERSION, '1.6.0', 'ge')) {

                $q = 'SELECT extension_id FROM `#__extensions` WHERE `element` = "'.$module.'" ';
                $db->setQuery($q);
                $ext_id = $db->loadResult();

                $action = '';
                if (empty($ext_id)) {
                    $q = 'INSERT INTO `#__extensions` 	(`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `manifest_cache`, `params`, `ordering`) VALUES
																	( "'.$module.'" , "module", "'.$module.'", "", "0", "1","'.$access.'", "0", "'.$db->getEscaped($data["manifest_cache"]).'", "'.$params.'","'.$ordering.'");';
                } else {
                    $q = 'UPDATE `#__extensions` SET 	`name`= "'.$module.'",
																	`type`= "module",
																	`element`= "'.$module.'",
																	`folder`= "",
																	`client_id`= "'.$client_id.'",
																	`enabled`= "1",
																	`access`= "'.$access.'",
																	`protected`= "0",
																	`manifest_cache` = "'.$db->getEscaped($data["manifest_cache"]).'",
																	`ordering`= "'.$ordering.'"

					WHERE `extension_id`= "'.$ext_id.'" ';
                }
                $db->setQuery($q);
                if (! $db->query()) {
                    $app = JFactory::getApplication();
                    $app->enqueueMessage(get_class($this).'::  '.$db->getErrorMsg());
                }
            }
        }

        /**
         * @param string $tablename
         * @param string $fields
         * @param string $command
         * @author Max Milbers
         */
        private function alterTable($tablename, $fields, $command = 'CHANGE')
        {
            if (empty($this->db)) {
                $this->db = JFactory::getDBO();
            }

            $query = 'SHOW COLUMNS FROM `'.$tablename.'` ';
            $this->db->setQuery($query);
            $columns = $this->db->loadResultArray(0);

            foreach ($fields as $fieldname => $alterCommand) {
                if (in_array($fieldname, $columns)) {
                    $query = 'ALTER TABLE `'.$tablename.'` '.$command.' COLUMN `'.$fieldname.'` '.$alterCommand;

                    $this->db->setQuery($query);
                    $this->db->query();
                }
            }
        }

        /**
         *
         * @param string $table
         * @param string $field
         * @param string $fieldType
         * @return boolean This gives true back, WHEN it altered the table, you may use this information to decide for extra post actions
         * @author Max Milbers
         */
        private function checkAddFieldToTable($table, $field, $fieldType)
        {
            $query = 'SHOW COLUMNS FROM `'.$table.'` ';
            $this->db->setQuery($query);
            $columns = $this->db->loadResultArray(0);

            if (! in_array($field, $columns)) {
                $query = 'ALTER TABLE `'.$table.'` ADD '.$field.' '.$fieldType;
                $this->db->setQuery($query);
                if (! $this->db->query()) {
                    $app = JFactory::getApplication();
                    $app->enqueueMessage('Install checkAddFieldToTable '.$this->db->getErrorMsg());

                    return false;
                } else {
                    return true;
                }
            }

            return false;
        }

        private function addToRequired($table, $fieldname, $fieldvalue, $insert)
        {
            if (empty($this->db)) {
                $this->db = JFactory::getDBO();
            }

            $query = 'SELECT * FROM `'.$table.'` WHERE '.$fieldname.' = "'.$fieldvalue.'" ';
            $this->db->setQuery($query);
            $result = $this->db->loadResult();
            if (empty($result) || ! $result) {
                $this->db->setQuery($insert);
                if (! $this->db->query()) {
                    $app = JFactory::getApplication();
                    $app->enqueueMessage('Install addToRequired '.$this->db->getErrorMsg());
                }
            }
        }

        private function updateShipperToShipment()
        {
            if (empty($this->db)) {
                $this->db = JFactory::getDBO();
            }
            if (version_compare(JVERSION, '1.6.0', 'ge')) {
                // Joomla! 1.6 code here
                $table = JTable::getInstance('extension');
                $tableName = '#__extensions';
                $idfield = 'extension_id';
            } else {

                // Joomla! 1.5 code here
                $table = JTable::getInstance('plugin');
                $tableName = '#__plugins';
                $idfield = 'id';
            }

            $q = 'SELECT '.$idfield.' FROM '.$tableName.' WHERE `folder` = "vmshipper" ';
            $this->db->setQuery($q);
            $result = $this->db->loadResult();
            if ($result) {
                $q = 'UPDATE `'.$tableName.'` SET `folder`="vmshipment" WHERE `extension_id`= '.$result;
                $this->db->setQuery($q);
                $this->db->query();
            }
        }

        private function getPaymentJPluginId($elementName)
        {
            if (version_compare(JVERSION, '1.6.0', 'ge')) {
                $table = JTable::getInstance('extension');
            } else {
                $table = JTable::getInstance('plugin');
            }

            $query = sprintf('SELECT extension_id FROM `%s` WHERE element = \'%s\'', $table->getTableName(), $elementName);
            $db = JFactory::getDBO();
            $db->setQuery($query);
            return $db->loadResult();
        }

        private function createPaymentMethod($paymentJPluginId)
        {
            VmConfig::loadJLang('com_virtuemart');
            VmConfig::loadJLang('com_virtuemart_orders', TRUE);
            $lang = $this->getLanguage();

            $data = array(
                'payment_name' => JText::_('VMPAYMENT_FRISBEE'),
                'slug' => 'frisbee',
                'published' => '1',
                'payment_desc' => JText::_('VMPAYMENT_FRISBEE_DESCRIPTION'),
                'payment_jplugin_id' => $paymentJPluginId,
                'ordering' => 0,
                'option' => 'com_virtuemart',
                'task' => 'apply',
                'boxchecked' => '0',
                'xxcontroller' => 'paymentmethod',
                'view' => 'paymentmethod',
            );

            $db = JFactory::getDBO();

            $languages = ['ru_ru', 'uk_ua'];
            foreach ($languages as $language) {
                $query = "
                CREATE TABLE IF NOT EXISTS `#__virtuemart_paymentmethods_{$lang}` (
                    virtuemart_paymentmethod_id INT,
                    payment_name VARCHAR(255),
                    payment_desc VARCHAR(255),
                    slug VARCHAR(255)
                )";
                $db->setQuery($query);
                $db->execute();
            }

            VmConfig::$vmlang = $lang;
            $model = new \VirtueMartModelPaymentmethod();
            $paymentMethodId = $model::store($data);

            $query = sprintf("INSERT IGNORE INTO `#__virtuemart_paymentmethods_%s` ".
                "(`virtuemart_paymentmethod_id`, `payment_name`, `payment_desc`, `slug`) " .
                "VALUES	(%d, '%s', '%s', 'frisbee')",
                $lang,
                $paymentMethodId,
                JText::_('VMPAYMENT_FRISBEE'),
                JText::_('VMPAYMENT_FRISBEE_DESCRIPTION')
            );
            $db->setQuery($query);
            $db->execute();

            $query = sprintf("INSERT IGNORE INTO `#__virtuemart_paymentmethods_%s` ".
                "(`virtuemart_paymentmethod_id`, `payment_name`, `payment_desc`, `slug`) " .
                "VALUES	(%d, '%s', '%s', 'frisbee')",
                'en_gb',
                $paymentMethodId,
                JText::_('VMPAYMENT_FRISBEE'),
                JText::_('VMPAYMENT_FRISBEE_DESCRIPTION')
            );
            $db->setQuery($query);
            $db->execute();

            if (!class_exists('vmPSPlugin')) {
                require(JPATH_ROOT . DS .  'vmpsplugin.php');
            }

            JPluginHelper::importPlugin('vmpayment');
            $dispatcher = JDispatcher::getInstance();
            $retValues = $dispatcher->trigger('plgVmOnStoreInstallPaymentPluginTable', array($paymentJPluginId));
        }

        /**
         * copy all $src to $dst folder and remove it
         *
         * @param String $src path
         * @param String $dst path
         * @author Max Milbers
         */
        private function recurse_copy($src, $dst)
        {
            $dir = opendir($src);
            $this->createIndexFolder($dst);

            if (is_resource($dir)) {
                while (false !== ($file = readdir($dir))) {
                    if (($file != '.') && ($file != '..')) {
                        if (is_dir($src.DS.$file)) {
                            $this->recurse_copy($src.DS.$file, $dst.DS.$file);
                        } else {
                            if (JFile::exists($dst.DS.$file)) {
                                if (! JFile::delete($dst.DS.$file)) {
                                    $app = JFactory::getApplication();
                                    $app->enqueueMessage('Couldnt delete '.$dst.DS.$file);
                                }
                            }
                            if (! JFile::move($src.DS.$file, $dst.DS.$file)) {
                                $app = JFactory::getApplication();
                                $app->enqueueMessage('Couldnt move '.$src.DS.$file.' to '.$dst.DS.$file);
                            }
                        }
                    }
                }
                closedir($dir);
                if (is_dir($src)) {
                    JFolder::delete($src);
                }
            }
        }

        public function vmUninstall()
        {
            $db = JFactory::getDBO();
            $lang = $this->getLanguage();

            if (version_compare(JVERSION, '3.0.0', 'lt')) {
                $query = "DELETE FROM `#__virtuemart_paymentmethods` WHERE `slug` = 'frisbee'";
                $db->setQuery($query);
                $db->execute();

                $query = sprintf('DELETE FROM `#__virtuemart_paymentmethods_%s` WHERE `slug` = \'frisbee\'', $lang);
                $db->setQuery($query);
                $db->execute();

                $query = 'DELETE FROM `#__virtuemart_paymentmethods_en_gb` WHERE `slug` = \'frisbee\'';
                $db->setQuery($query);
                $db->execute();
            } else {
                $query = "DELETE FROM `#__virtuemart_paymentmethods` WHERE `payment_element` = 'frisbee'";
                $db->setQuery($query);
                $db->execute();

                $query = sprintf('DELETE FROM `#__virtuemart_paymentmethods_%s` WHERE `slug` = \'frisbee\'', $lang);
                $db->setQuery($query);
                $db->execute();

                $query = 'DELETE FROM `#__virtuemart_paymentmethods_en_gb` WHERE `slug` = \'frisbee\'';
                $db->setQuery($query);
                $db->execute();
            }
        }

        public function uninstall()
        {
            $this->vmUninstall();

            return true;
        }

        /**
         * creates a folder with empty html file
         *
         * @author Max Milbers
         *
         */
        public function createIndexFolder($path)
        {

            if (JFolder::create($path)) {
                if (! JFile::exists($path.DS.'index.html')) {
                    JFile::copy(JPATH_ROOT.DS.'components'.DS.'index.html', $path.DS.'index.html');
                }

                return true;
            }

            return false;
        }

        /**
         * Method to get a table object, load it if necessary.
         *
         * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
         * @license     GNU General Public License version 2 or later; see LICENSE
         *
         * @param   string  $name     The table name. Optional.
         * @param   string  $prefix   The class prefix. Optional.
         * @param   array   $options  Configuration array for model. Optional.
         *
         * @return  JTable  A JTable object
         *
         * @since   11.1
         */
        public function getTable($name = '', $prefix = 'Table', $options = array())
        {
            return \VirtueMartModelPaymentmethod::getTable($name, $prefix, $options);
        }

        public function _createTable($name = '', $prefix = 'Table', $config = array())
        {
            $config = array();
            $config['dbo'] = JFactory::getDbo();

            return VmTable::getInstance($name, $prefix, $config);
        }

        private function getLanguage()
        {
            if(!VmConfig::$vmlang){
                $params = JComponentHelper::getParams('com_languages');
                $lang = $params->get('site', 'en-GB');
                $lang = strtolower(strtr($lang,'-','_'));
            } else {
                $lang = VmConfig::$vmlang;
            }

            if (!$lang) {
                jimport('joomla.language.helper');
                $lang = JFactory::getLanguage()->getTag();
            }

            return $lang;
        }
    }

    function com_install()
    {
        if (! version_compare(JVERSION, '1.6.0', 'ge')) {
            $vmInstall = new com_VirtueMart_frisbee_pluginInstallerScript();
            $vmInstall->vmInstall();
        }

        return true;
    }

    function com_uninstall()
    {
        if (! version_compare(JVERSION, '1.6.0', 'ge')) {
            $vmInstall = new com_VirtueMart_frisbee_pluginInstallerScript();
            $vmInstall->vmUninstall();
        }

        return true;
    }

    class plgVmPaymentFrisbeeInstallerScript extends com_VirtueMart_frisbee_pluginInstallerScript {}
}

?>
