<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class jeestrompi extends eqLogic {
  /*     * *************************Attributs****************************** */

  /*
  * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
  * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
  public static $_widgetPossibility = array();
  */

  /*
  * Permet de crypter/décrypter automatiquement des champs de configuration du plugin
  * Exemple : "param1" & "param2" seront cryptés mais pas "param3"
  public static $_encryptConfigKey = array('param1', 'param2');
  */

  /*     * ***********************Methode static*************************** */
	 public static function deamon_info() {
        $return = array();
        $return['log'] = __CLASS__;
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            if (@posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }
        $return['launchable'] = 'ok';
        $strompiserialport = config::byKey('strompiserialport', __CLASS__); // exemple si votre démon à besoin de la config user,
        $strompiserialbaud = config::byKey('strompiserialbaud', __CLASS__); // password,
        $strompidsocketport = config::byKey('strompidsocketport', __CLASS__); // et clientId
        if ($strompiserialport == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Le port serie n\'est pas configuré', __FILE__);
        } elseif ($strompiserialbaud == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('La vitesse n\'est pas configuré', __FILE__);
        } elseif ($strompidsocketport == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Le port d\'ecoute n\'est pas configurée', __FILE__);
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('jeestrompi') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
    }

    
	public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }

        $path = realpath(dirname(__FILE__) . '/../../resources/demond'); // répertoire du démon à modifier
        $cmd = 'python3 ' . $path . '/jeestrompyd.py'; // nom du démon à modifier
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
        $cmd .= ' --socketport ' . config::byKey('strompidsocketport', __CLASS__, '55009'); // port par défaut à modifier
        $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/jeestrompi/core/php/jeestrompi.php'; // chemin de la callback url à modifier (voir ci-dessous)
        $cmd .= ' --serialport ' . config::byKey('strompiserialport', __CLASS__, '/dev/serial0');
        $cmd .= ' --serialbaud ' . config::byKey('strompiserialbaud', __CLASS__, '38400');
		$cmd .= ' --cycle' . config::byKey('jeestrompicycle', __CLASS__, '0.3');
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__); // l'apikey pour authentifier les échanges suivants
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid'; // et on précise le chemin vers le pid file (ne pas modifier)
        log::add(__CLASS__, 'info', 'Lancement démon');
        $result = exec($cmd . ' >> ' . log::getPathToLog('jeestrompyd') . ' 2>&1 &'); // 'template_daemon' est le nom du log pour votre démon, vous devez nommer votre log en commençant par le pluginid pour que le fichier apparaisse dans la page de config
        $i = 0;
        while ($i < 20) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 30) {
            log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
            return false;
        }
        message::removeAll(__CLASS__, 'unableStartDeamon');
        return true;
    }

	
	public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder('jeestrompi') . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }
        system::kill('jeestrompyd.py');
        system::fuserk(config::byKey('strompidsocketport', 'jeestrompi'));
        sleep(1);
    }
  /*
  * Fonction exécutée automatiquement toutes les minutes par Jeedom
  public static function cron() {}
  */
   public static function cron() {
		$dateRun = new DateTime();
        log::add('jeestrompi', 'info', 'start autorefresh ');
		foreach (eqLogic::byType('jeestrompi') as $eqLogic) {
			$autorefresh = $eqLogic->getConfiguration('autorefresh');
            log::add('jeestrompi', 'info', 'autorefresh value = '.$autorefresh);
			if ($autorefresh != '') {
				try {
					$c = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory);
					if ($c->isDue()) {
						$eqLogic->refresh();
					}
				} catch (Exception $exc) {
					log::add('jeestrompi', 'error', __('Expression cron non valide pour', __FILE__) . ' ' . $eqLogic->getHumanName() . ' : ' . $autorefresh);
				}
			}
		}
	}
  
   public function refresh() {
		foreach ($this->getCmd('info') as $cmd) {
			try {
				$cmd->execute();
			} catch (Exception $exc) {
				log::add('jeestrompi', 'error', __('Erreur pour ', __FILE__) . $cmd->getHumanName() . ' : ' . $exc->getMessage());
			}
		}
	}
  /*
  
  * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
  public static function cron5() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
  public static function cron15() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
  public static function cron30() {}
  */
  public function randomVdm() {
    $url = "http://www.viedemerde.fr/aleatoire";
    $data = file_get_contents($url);
    @$dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($data);
    libxml_use_internal_errors(false);
    $xpath = new DOMXPath($dom);
    $divs = $xpath->query('//a[@class="block text-blue-500 dark:text-white my-4 "][1]');
    /*return $xpath;*/
    return $divs[0]->nodeValue ;
  }
  
  public function strompiquerrry() {
    $plugin = plugin::byId('jeestrompi');
    $strompiserialport = config::byKey("strompiserialport", 'jeestrompi');
    $strompiserialbaud = config::byKey("strompiserialbaud", 'jeestrompi');
    /*$strompiserialport = $plugin->getConfiguration("strompiserialport", "/dev/serial0");
    $strompiserialbaud = $plugin->getConfiguration("strompiserialbaud", "38400");*/
    log::add('jeestrompi', 'info', 'config strompi serial : '.$strompiserialport.'  - '.$strompiserialbaud.' bauds');
    $output=shell_exec('python3 /var/www/html/plugins/jeestrompi/resources/StromPi3_Status_jeedom.py 2>&1');
    list($StromPiMode, $StromPiLifePo4V, $StromPiLifePo4Charge, $StromPiWide, $StromPiUSB, $StromPiOutput) = explode("|", $output);
    if ($StromPiWide == '')
    {
      $StromPiWide=0;
    }
    if ($StromPiMode == '')
    {
      $StromPiMode=-1;
    }
    if ($StromPiLifePo4V == '')
    {
      $StromPiLifePo4V=-1;
    }
    if ($StromPiLifePo4Charge == '')
    {
      $StromPiLifePo4Charge=-1;
    }else {
      $vowels = array("]", "[", "(", ")");
      $StromPiLifePo4Charge=str_replace("]", " ",$StromPiLifePo4Charge);
      $StromPiLifePo4Charge=str_replace($vowels, "",$StromPiLifePo4Charge);
    }
    if ($StromPiWide == '')
    {
      $StromPiWide=-1;
    }
    if ($StromPiUSB == '')
    {
      $StromPiUSB=-1;
    }
    $strompiarray = array($StromPiMode,$StromPiLifePo4V,$StromPiWide,$StromPiUSB,$StromPiLifePo4Charge,$StromPiOutput);
    return $strompiarray;
  }
  /*
  * Fonction exécutée automatiquement toutes les heures par Jeedom
  public static function cronHourly() {}
  */
  
  /*
  * Fonction exécutée automatiquement tous les jours par Jeedom
  public static function cronDaily() {}
  */
  
  /*
  * Permet de déclencher une action avant modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function preConfig_param3( $value ) {
    // do some checks or modify on $value
    return $value;
  }
  */

  /*
  * Permet de déclencher une action après modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function postConfig_param3($value) {
    // no return value
  }
  */

  /*
   * Permet d'indiquer des éléments supplémentaires à remonter dans les informations de configuration
   * lors de la création semi-automatique d'un post sur le forum community
   public static function getConfigForCommunity() {
      return "les infos essentiel de mon plugin";
   }
   */

  /*     * *********************Méthodes d'instance************************* */

  // Fonction exécutée automatiquement avant la création de l'équipement
  public function preInsert() {
  }

  // Fonction exécutée automatiquement après la création de l'équipement
  public function postInsert() {
    
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
  }

  // Fonction exécutée automatiquement après la mise à jour de l'équipement
  public function postUpdate() {
   /*self::cronHourly($this->getId()); //lance la fonction cronHourly avec l’id de l’eqLogic*/
  }


  // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
  public function preSave() {
  }

  // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
  public function postSave() {
  $refresh = $this->getCmd(null, 'refresh');
  if (!is_object($refresh)) {
    $refresh = new jeestrompiCmd();
    $refresh->setName(__('Rafraichir', __FILE__));
  }
  $refresh->setEqLogic_id($this->getId());
  $refresh->setLogicalId('refresh');
  $refresh->setType('action');
  $refresh->setSubType('other');
  $refresh->save();	  
 
  $StromPiOutput = $this->getCmd(null, 'StromPiOutput');
  if (!is_object($StromPiOutput)) {
    $StromPiOutput = new jeestrompiCmd();
    $StromPiOutput->setName(__('Strompi Output', __FILE__));
  }
  $StromPiOutput->setLogicalId('StromPiOutput');
  $StromPiOutput->setEqLogic_id($this->getId());
  $StromPiOutput->setType('info');
  $StromPiOutput->setTemplate('dashboard','tile');//template pour le dashboard
  $StromPiOutput->setSubType('numeric');
  $StromPiOutput->save();
 
 $StromPiLifePo4Charge = $this->getCmd(null, 'StromPiLifePo4Charge');
  if (!is_object($StromPiLifePo4Charge)) {
    $StromPiLifePo4Charge = new jeestrompiCmd();
    $StromPiLifePo4Charge->setName(__('Strompi LifePo4Charge', __FILE__));
  }
  $StromPiLifePo4Charge->setLogicalId('StromPiLifePo4Charge');
  $StromPiLifePo4Charge->setEqLogic_id($this->getId());
  $StromPiLifePo4Charge->setType('info');
  $StromPiLifePo4Charge->setTemplate('dashboard','tile');//template pour le dashboard
  $StromPiLifePo4Charge->setSubType('string');
  $StromPiLifePo4Charge->save();
 
  $StromPiLifePo4 = $this->getCmd(null, 'StromPiLifePo4');
  if (!is_object($StromPiLifePo4)) {
    $StromPiLifePo4 = new jeestrompiCmd();
    $StromPiLifePo4->setName(__('Strompi LifePo4', __FILE__));
  }
  $StromPiLifePo4->setLogicalId('StromPiLifePo4');
  $StromPiLifePo4->setEqLogic_id($this->getId());
  $StromPiLifePo4->setType('info');
  $StromPiLifePo4->setTemplate('dashboard','tile');//template pour le dashboard
  $StromPiLifePo4->setSubType('numeric');
  $StromPiLifePo4->save();

  $StromPiUSB = $this->getCmd(null, 'StromPiUSB');
  if (!is_object($StromPiUSB)) {
    $StromPiUSB = new jeestrompiCmd();
    $StromPiUSB->setName(__('Strompi USB', __FILE__));
  }
  $StromPiUSB->setLogicalId('StromPiUSB');
  $StromPiUSB->setEqLogic_id($this->getId());
  $StromPiUSB->setType('info');
  $StromPiUSB->setTemplate('dashboard','tile');//template pour le dashboard
  $StromPiUSB->setSubType('numeric');
  $StromPiUSB->save();
  
  $StromPiWide = $this->getCmd(null, 'StromPiWide');
  if (!is_object($StromPiWide)) {
    $StromPiWide = new jeestrompiCmd();
    $StromPiWide->setName(__('Strompi Wide', __FILE__));
  }
  $StromPiWide->setLogicalId('StromPiWide');
  $StromPiWide->setEqLogic_id($this->getId());
  $StromPiWide->setType('info');
  $StromPiWide->setTemplate('dashboard','tile');//template pour le dashboard
  $StromPiWide->setSubType('numeric');
  $StromPiWide->save();
  
  $strompimode = $this->getCmd(null, 'strompimode');
  if (!is_object($strompimode)) {
    $strompimode = new jeestrompiCmd();
    $strompimode->setName(__('Strompi Mode', __FILE__));
  }
  $strompimode->setLogicalId('strompimode');
  $strompimode->setEqLogic_id($this->getId());
  $strompimode->setType('info');
  $strompimode->setTemplate('dashboard','tile');//template pour le dashboard
  $strompimode->setSubType('numeric');
  $strompimode->save();
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  public function preRemove() {
  }

  // Fonction exécutée automatiquement après la suppression de l'équipement
  public function postRemove() {
  }

  /*
  * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
  * Exemple avec le champ "Mot de passe" (password)
  public function decrypt() {
    $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
  }
  public function encrypt() {
    $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
  }
  */

  /*
  * Permet de modifier l'affichage du widget (également utilisable par les commandes)
  public function toHtml($_version = 'dashboard') {}
  */
  
  /*     * **********************Getteur Setteur*************************** */
}

class jeestrompiCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
  public static $_widgetPossibility = array();
  */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
  * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
  public function dontRemoveCmd() {
    return true;
  }
  */
  
  // Exécution d'une commande
  public function execute($_options = array()) {
    $eqlogic = $this->getEqLogic(); //récupère l'éqlogic de la commande $this
    log::add('jeestrompi', 'info', 'Lancement update par '.$this->getLogicalId());
    switch ($this->getLogicalId()) { //vérifie le logicalid de la commande
    case 'refresh': // LogicalId de la commande rafraîchir que l’on a créé dans la méthode Postsave de la classe vdm .
     log::add('jeestrompi', 'info', 'mise a jour des valeurs de la carte strompi');
     $strompiTab = $eqlogic->strompiquerrry();
     /*$info = $eqlogic->randomVdm(); //On lance la fonction randomVdm() pour récupérer une vdm et on la stocke dans la variable $info*/
     $info = $strompiTab[0];
     $eqlogic->checkAndUpdateCmd('strompimode', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[1];
     $eqlogic->checkAndUpdateCmd('StromPiLifePo4', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[2];
     $eqlogic->checkAndUpdateCmd('StromPiWide', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[3];
     $eqlogic->checkAndUpdateCmd('StromPiUSB', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[4];
     $eqlogic->checkAndUpdateCmd('StromPiLifePo4Charge', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[5];
     $eqlogic->checkAndUpdateCmd('StromPiOutput', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
    break;
    case 'strompimode': // LogicalId de la commande rafraîchir que l’on a créé dans la méthode Postsave de la classe vdm .
     log::add('jeestrompi', 'info', 'mise a jour des valeurs de la carte strompi');
     $strompiTab = $eqlogic->strompiquerrry();
     /*$info = $eqlogic->randomVdm(); //On lance la fonction randomVdm() pour récupérer une vdm et on la stocke dans la variable $info*/
     $info = $strompiTab[0];
     $eqlogic->checkAndUpdateCmd('strompimode', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[1];
     $eqlogic->checkAndUpdateCmd('StromPiLifePo4', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[2];
     $eqlogic->checkAndUpdateCmd('StromPiWide', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[3];
     $eqlogic->checkAndUpdateCmd('StromPiUSB', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[4];
     $eqlogic->checkAndUpdateCmd('StromPiLifePo4Charge', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
	 $info = $strompiTab[5];
     $eqlogic->checkAndUpdateCmd('StromPiOutput', $info); //on met à jour la commande avec le LogicalId "story"  de l'eqlogic
    break;
    }
}

  /*     * **********************Getteur Setteur*************************** */
}