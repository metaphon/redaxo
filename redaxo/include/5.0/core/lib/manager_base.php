<?php

/**
 * Managerklasse zum handeln von rexAddons
 */
abstract class rex_baseManager
{
  var $i18nPrefix;

  /**
   * Konstruktor
   *
   * @param $i18nPrefix Sprachprefix aller I18N Sprachschlüssel
   */
  function __construct($i18nPrefix)
  {
    $this->i18nPrefix = $i18nPrefix;
  }

  /**
   * Installiert ein Addon
   *
   * @param $addonName Name des Addons
   * @param $installDump Flag, ob die Datei install.sql importiert werden soll
   */
  public function install($addonName, $installDump = TRUE)
  {
  	global $REX;

    $state = TRUE;

    $install_dir  = $this->baseFolder($addonName);
    $install_file = $install_dir.'install.inc.php';
    $install_sql  = $install_dir.'install.sql';
    $config_file  = $install_dir.'config.inc.php';
    $files_dir    = $install_dir.'files';
    $package_file = $install_dir.'package.yml';

    // Pruefen des Addon Ornders auf Schreibrechte,
    // damit das Addon spaeter wieder geloescht werden kann
    $state = rex_is_writable($install_dir);

    if ($state === TRUE)
    {
      // load package infos
      $this->loadPackageInfos($addonName);

      // check if requirements are met
      $state = $this->checkRequirements($addonName);

      if($state === TRUE)
      {
        // check if install.inc.php exists
        if (is_readable($install_file))
        {
          $this->includeInstaller($addonName, $install_file);
          $state = $this->verifyInstallation($addonName);
        }
        else
        {
          // no install file -> no error
          $this->apiCall('setProperty', array($addonName, 'install', 1));
        }

        if($state === TRUE && $installDump === TRUE && is_readable($install_sql))
        {
          $state = rex_install_dump($install_sql);

          if($state !== TRUE)
            $state = 'Error found in install.sql:<br />'. $state;
        }

        // Installation ok
        if ($state === TRUE)
        {
          // regenerate Addons file
          $state = $this->generateConfig();
        }
      }
    }

    // Dateien kopieren
    if($state === TRUE && is_dir($files_dir))
    {
      if(!rex_copyDir($files_dir, $this->mediaFolder($addonName), $REX['OPENMEDIAFOLDER']))
      {
        $state = $this->I18N('install_cant_copy_files');
      }
    }

    if($state !== TRUE)
      $this->apiCall('setProperty', array($addonName, 'install', 0));

    return $state;
  }

  /**
   * De-installiert ein Addon
   *
   * @param $addonName Name des Addons
   */
  public function uninstall($addonName)
  {
    $state = TRUE;

    $install_dir    = $this->baseFolder($addonName);
    $uninstall_file = $install_dir.'uninstall.inc.php';
    $uninstall_sql  = $install_dir.'uninstall.sql';
    $package_file   = $install_dir.'package.yml';

    // check if another Addon which is installed, depends on the addon being un-installed
    foreach(OOAddon::getAvailableAddons() as $availAddonName)
    {
      $requirements = OOAddon::getProperty($availAddonName, 'requires', array());
      if(isset($requirements['addons']))
      {
        foreach($requirements['addons'] as $depName => $depAttr)
        {
          if($depName == $addonName)
          {
            $state = 'Addon "'. $addonName .'" is required by installed Addon "'. $availAddonName .'"!';
            break 2;
          }
        }
      }

      // check if another Plugin which is installed, depends on the addon being un-installed
      foreach(OOPlugin::getAvailablePlugins($availAddonName) as $availPluginName)
      {
        $requirements = OOPlugin::getProperty($availAddonName, $availPluginName, 'requires', array());
        if(isset($requirements['addons']))
        {
          foreach($requirements['addons'] as $depName => $depAttr)
          {
            if($depName == $addonName)
            {
              $state = 'Addon "'. $addonName .'" is required by installed Plugin "'. $availPluginName .'" of Addon "'. $availAddonName .'"!';
              break 3;
            }
          }
        }
      }
    }

    // start un-installation
    if($state === TRUE)
    {
      // check if uninstall.inc.php exists
      if (is_readable($uninstall_file))
      {
        $this->includeUninstaller($addonName, $uninstall_file);
        $state = $this->verifyUninstallation($addonName);
      }
      else
      {
        // no uninstall file -> no error
        $this->apiCall('setProperty', array($addonName, 'install', 0));
      }
    }

    if($state === TRUE)
    {
      $state = $this->deactivate($addonName);

      if($state === TRUE && is_readable($uninstall_sql))
      {
        $state = rex_install_dump($uninstall_sql);

        if($state !== TRUE)
          $state = 'Error found in uninstall.sql:<br />'. $state;
      }

      if ($state === TRUE)
      {
        // regenerate Addons file
        $state = $this->generateConfig();
      }
    }

    $mediaFolder = $this->mediaFolder($addonName);
    if($state === TRUE && is_dir($mediaFolder))
    {
      if(!rex_deleteDir($mediaFolder, TRUE))
      {
        $state = $this->I18N('install_cant_delete_files');
      }
    }

    // Fehler beim uninstall -> Addon bleibt installiert
    if($state !== TRUE)
      $this->apiCall('setProperty', array($addonName, 'install', 1));

    return $state;
  }

  /**
   * Aktiviert ein Addon
   *
   * @param $addonName Name des Addons
   */
  public function activate($addonName)
  {
    if ($this->apiCall('isInstalled', array($addonName)))
    {
      $this->apiCall('setProperty', array($addonName, 'status', 1));
      $state = $this->generateConfig();
    }
    else
    {
      $state = $this->I18N('no_activation', $addonName);
    }

    // error while config generation, rollback addon status
    if($state !== TRUE)
      $this->apiCall('setProperty', array($addonName, 'status', 0));

    return $state;
  }

  /**
   * Deaktiviert ein Addon
   *
   * @param $addonName Name des Addons
   */
  public function deactivate($addonName)
  {
    $this->apiCall('setProperty', array($addonName, 'status', 0));
    $state = $this->generateConfig();

    // error while config generation, rollback addon status
    if($state !== TRUE)
      $this->apiCall('setProperty', array($addonName, 'status', 1));

    // reload autoload cache when addon is deactivated,
    // so the index doesn't contain outdated class definitions
    if($state === TRUE)
      rex_autoload::getInstance()->removeCache();

    return $state;
  }

  /**
   * Löscht ein Addon im Filesystem
   *
   * @param $addonName Name des Addons
   */
  public function delete($addonName)
  {
    // zuerst deinstallieren
    // bei erfolg, komplett löschen
    $state = TRUE;
    $state = $state && $this->uninstall($addonName);
    $state = $state && rex_deleteDir($this->baseFolder($addonName), TRUE);
    $state = $state && $this->generateConfig();

    return $state;
  }

  /**
   * Moves the addon one step forward in the include-chain.
   * The addon will therefore be included earlier in the bootstrap process.
   *
   * @param $addonName Name of the addon
   */
  public abstract function moveUp($addonName);

  /**
   * Moves the addon one step backwards in the include-chain.
   * The addon will therefore be included later in the bootstrap process.
   *
   * @param $addonName Name of the addon
   */
  public abstract function moveDown($addonName);

  /**
   * Verifies if the installation of the given Addon was successfull.
   *
   * @param string $addonName The name of the addon
   */
  private function verifyInstallation($addonName)
  {
    $state = TRUE;

    // Wurde das "install" Flag gesetzt?
    // Fehlermeldung ausgegeben? Wenn ja, Abbruch
    $instmsg = $this->apiCall('getProperty', array($addonName, 'installmsg', ''));

    if (!$this->apiCall('isInstalled', array($addonName)) || $instmsg)
    {
      $state = $this->I18N('no_install', $addonName).'<br />';
      if ($instmsg == '')
      {
        $state .= $this->I18N('no_reason');
      }
      else
      {
        $state .= $instmsg;
      }
    }

    return $state;
  }

  /**
   * Verifies if the un-installation of the given Addon was successfull.
   *
   * @param string $addonName The name of the addon
   */
  private function verifyUninstallation($addonName)
  {
    $state = TRUE;

    // Wurde das "install" Flag gesetzt?
    // Fehlermeldung ausgegeben? Wenn ja, Abbruch
    $instmsg = $this->apiCall('getProperty', array($addonName, 'installmsg', ''));

    if ($this->apiCall('isInstalled', array($addonName)) || $instmsg)
    {
      $state = $this->I18N('no_uninstall', $addonName).'<br />';
      if ($instmsg == '')
      {
        $state .= $this->I18N('no_reason');
      }
      else
      {
        $state .= $instmsg;
      }
    }

    return $state;
  }

  /**
   * Checks whether the given requirements are met.
   *
   * @param array $requirements
   */
  private function checkRequirements($addonName)
  {
    global $REX;

    $state = TRUE;
    $rexVers = $REX['VERSION'] .'.'. $REX['SUBVERSION'] .'.'. $REX['MINORVERSION'];
    $requirements = $this->apiCall('getProperty', array($addonName, 'requires', array()));

    foreach($requirements as $reqName => $reqAttr)
    {
      switch($reqName)
      {
        case 'redaxo':
        {
          // check dependency exact-version
          if(isset($reqAttr['version']) && version_compare($rexVers, $reqAttr['version']) == 0)
          {
            $state = 'Addon requires REDAXO "'. $reqAttr['version'] . '", but "'. $rexVers .'" is installed!';
          }
          else
          {
            // check dependency min-version
            if(isset($reqAttr['min-version']) && version_compare($rexVers, $reqAttr['min-version']) == -1)
            {
              $state = 'Addon requires at least REDAXO "'. $reqAttr['min-version'] . '", but "'. $rexVers .'" is installed!';
            }
            // check dependency min-version
            else if(isset($reqAttr['max-version']) && version_compare($rexVers, $reqAttr['max-version']) == 1)
            {
              $state = 'Addon requires at most REDAXO "'. $reqAttr['max-version'] . '", but "'. $rexVers .'" is installed!';
            }
          }
          break;
        }
        case 'php-extension':
        {
          if(!is_array($reqAttr))
          {
            throw new InvalidArgumentException('Expecting php-extension to be a array, "'. gettype($reqAttr) .'" given!');
          }
          foreach($reqAttr as $reqExt)
          {
            if(is_string($reqExt))
            {
              if(!extension_loaded($reqExt))
              {
                $state = 'Missing required php-extension "'. $reqExt .'"!';
                break;
              }
            }
          }
        }
        case 'addons':
        {
          if(!is_array($reqAttr))
          {
            throw new InvalidArgumentException('Expecting addons to be a array, "'. gettype($reqAttr) .'" given!');
          }
          foreach($reqAttr as $depName => $depAttr)
          {
            // check if dependency exists
            if(!OOAddon::isAvailable($depName))
            {
              $state = 'Missing required Addon "'. $depName .'"!';
              break;
            }

            // check dependency exact-version
            if(isset($depAttr['version']) && version_compare(OOAddon::getProperty($depName, 'version'), $depAttr['version']) == 0)
            {
              $state = 'Required Addon "'. $depName . '" not in required version "'. $depAttr['version'] . '" (found: "'. OOAddon::getProperty($depName, 'version') .'")';
              break;
            }
            else
            {
              // check dependency min-version
              if(isset($depAttr['min-version']) && version_compare(OOAddon::getProperty($depName, 'version'), $depAttr['min-version']) == -1)
              {
                $state = 'Required Addon "'. $depName . '" not in required version! Requires at least "'. $depAttr['min-version'] . '", but found: "'. OOAddon::getProperty($depName, 'version') .'"!';
                break;
              }
              // check dependency min-version
              else if(isset($depAttr['max-version']) && version_compare(OOAddon::getProperty($depName, 'version'), $depAttr['max-version']) == 1)
              {
                $state = 'Required Addon "'. $depName . '" not in required version! Requires at most "'. $depAttr['max-version'] . '", but found: "'. OOAddon::getProperty($depName, 'version') .'"!';
                break;
              }
            }
          }
        }
      }
    }

    return $state;
  }

  /**
   * Übersetzen eines Sprachschlüssels unter Verwendung des Prefixes
   */
  protected function I18N()
  {
    global $I18N;

    $args = func_get_args();
    $args[0] = $this->i18nPrefix. $args[0];

    return rex_call_func(array($I18N, 'msg'), $args, false);
  }

  /**
   * Bindet die config-Datei eines Addons ein
   */
  protected abstract function includeConfig($addonName, $configFile);

  /**
   * Bindet die installations-Datei eines Addons ein
   */
  protected abstract function includeInstaller($addonName, $installFile);

  /**
   * Bindet die deinstallations-Datei eines Addons ein
   */
  protected abstract function includeUninstaller($addonName, $uninstallFile);

  /**
   * Speichert den aktuellen Zustand
   */
  protected abstract function generateConfig();

  /**
   * Ansprechen einer API funktion
   *
   * @param $method Name der Funktion
   * @param $arguments Array von Parametern/Argumenten
   */
  protected abstract function apiCall($method, $arguments);

  /**
   * Laedt die package.yml in $REX
   */
  protected abstract function loadPackageInfos($addonName);

  /**
   * Findet den Basispfad eines Addons
   */
  protected abstract function baseFolder($addonName);

  /**
   * Findet den Basispfad für Media-Dateien
   */
  protected abstract function mediaFolder($addonName);
}
