<?php
/*
Plugin Name: Github Updater
Plugin URI: https://gofraug.com/
Description: Mise a jour de plugins via github
Version: 0.1
Author: GRAU Alexis
Author URI: https://gofraug.com/
Text Domain: github-updater
*/

class GithubUpdater{

    private $baseFolder = WP_CONTENT_DIR.'/plugins';
    private $firstLoop = true;
    private $updateVerifyTime = 30;

    public function __construct(){
        date_default_timezone_set('Europe/Paris');
        add_action('admin_menu', [$this, 'admin_menus']);
        add_action('admin_init', [$this, 'posts_process']);
        add_action('admin_init', [$this, 'check_for_updates']);

        add_filter('plugins_api', array( $this, 'info' ), 20, 3);
        add_filter('site_transient_update_plugins', array($this, 'update'));
        add_filter('upgrader_package_options', array($this, 'pre_install_plugin'));
        add_filter('upgrader_install_package_result', array($this, 'post_install_plugin'), 10, 2);
    }

    public function post_install_plugin($result, $options){

        $pluginName = '';

        if(empty($options['plugin'])){ return $result; }

        $pluginMainFile = $options['plugin'];
        $path = explode('/', $pluginMainFile);

        if(count($path) == 2){ $pluginName = $path[0]; }
        else if(count($path) == 1){ $pluginName = explode('.', $pluginMainFile)[0]; }

        if(empty($pluginName)){ return $result; }

        $githubUpdaterPlugins = get_option('github_updater_plugins', []);

        foreach ($githubUpdaterPlugins as $id => $plugin) {
            if($plugin['plugin_name'] == $pluginName){
                $githubUpdaterPlugins[$id]['local_version'] = $githubUpdaterPlugins[$id]['remote_version'];
                update_option('github_updater_plugins', $githubUpdaterPlugins);
                $zipPath = __DIR__.'/updates/'.$pluginName.'.zip';
                if(file_exists($zipPath)){ unlink($zipPath); }
                return $result;
            }
        }

        return $result;
    }

    public function pre_install_plugin($options){

        $pluginName = '';

        if(empty($options['hook_extra'])){ return $options; }
        if(empty($options['hook_extra']['plugin'])){ return $options; }

        $pluginMainFile = $options['hook_extra']['plugin'];
        $path = explode('/', $pluginMainFile);

        if(count($path) == 2){ $pluginName = $path[0]; }
        else if(count($path) == 1){ $pluginName = explode('.', $pluginMainFile)[0]; }

        if(empty($pluginName)){ return $options; }

        $githubUpdaterPlugins = get_option('github_updater_plugins', []);

        foreach ($githubUpdaterPlugins as $plugin) {
            if($plugin['plugin_name'] == $pluginName){
                $path = $this->get_path_from_github_url($plugin['plugin_github_path']);
                $package = $this->download_latest($plugin['plugin_name'], $path);
                if(empty($package)){ return $options; }
                $options['package'] = $package;
                return $options;
            }
        }

        return $options;
    }

    public function check_for_updates(){

        $githubUpdaterPlugins = get_option('github_updater_plugins', []);
        $updated = false;

        foreach ($githubUpdaterPlugins as $id => $plugin) {

            if(!empty($plugin['last_update_check']) && time() < $plugin['last_update_check'] + $this->updateVerifyTime){ continue; }

            $path = $this->get_path_from_github_url($plugin['plugin_github_path']);
            $remote = $this->get_github_plugin_version($plugin['plugin_name'], $path);
            $local = get_plugin_data($this->baseFolder.'/'.$plugin['plugin_name'].'/'.$plugin['plugin_name'].'.php');

            $githubUpdaterPlugins[$id]['remote_version'] = $remote['Version'];
            $githubUpdaterPlugins[$id]['local_version'] = $local['Version'];
            $githubUpdaterPlugins[$id]['last_update_check'] = time();
            $updated = true;
        }

        if($updated){
            update_option('github_updater_plugins', $githubUpdaterPlugins);
        }
    }

	public function info($res, $action, $args){
		if('plugin_information' !== $action) { return $res; }

        $githubUpdaterPlugins = get_option('github_updater_plugins', []);

        foreach ($githubUpdaterPlugins as $plugin) {
            if($plugin['plugin_name'] == $args->slug){
                $res = new stdClass();
                $res->name = $plugin['plugin_name'];
        		return $res;
            }
        }

		return $res;
	}

	public function update($transient){

		if(empty($transient->checked)){ return $transient; }

        $githubUpdaterPlugins = get_option('github_updater_plugins', []);

        foreach ($githubUpdaterPlugins as $id => $plugin) {

            if(empty($plugin['remote_version']) || empty($plugin['local_version'])){ continue; }

    		if(version_compare($plugin['local_version'], $plugin['remote_version'], '<')){
                $remote = new stdClass();
                $remote->slug = $plugin['plugin_name'];
                $remote->plugin = $plugin['plugin_name'].'/'.$plugin['plugin_name'].'.php';
                $remote->new_version = $plugin['remote_version'];
                $remote->package = site_url().'/wp-content/plugins/github-updater/updates/'.$plugin['plugin_name'].'.zip';
                $path = $this->get_path_from_github_url($plugin['plugin_github_path']);
    			$transient->response[$remote->plugin] = $remote;
            }
        }

		return $transient;
	}


    function rrmdir($dir){
        if(is_dir($dir)){
            $objects = scandir($dir);
            foreach ($objects as $object){
                if($object != "." && $object != ".."){
                    if(is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object)){
                        $this->rrmdir($dir. DIRECTORY_SEPARATOR .$object);
                    }else{
                        unlink($dir. DIRECTORY_SEPARATOR .$object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function admin_menus(){
        add_submenu_page('tools.php', 'Github Updater', 'Github Updater', 'manage_options', 'gu_admin', [$this, 'admin_front']);
    }

    public function posts_process(){

        if(empty($_GET['page']) || $_GET['page'] != 'gu_admin'){ return; }

        if(!empty($_POST['username']) && !empty($_POST['token'])){
            update_option('github_updater_credentials', ['username' => $_POST['username'], 'token' => $_POST['token']]);
        }

        if(!empty($_POST['plugin_name']) && !empty($_POST['plugin_github_path']) && !empty($_POST['action']) && $_POST['action'] == 'add'){

            $githubUpdaterPlugins = get_option('github_updater_plugins', []);

            $githubUpdaterPlugins[] = [
                'plugin_name' => $_POST['plugin_name'],
                'plugin_github_path' => $_POST['plugin_github_path']
            ];

            update_option('github_updater_plugins', $githubUpdaterPlugins);
        }

        if(!empty($_GET['action']) && $_GET['action'] == 'update_plugin' && isset($_GET['id'])){

            $githubUpdaterPlugins = get_option('github_updater_plugins', []);
            $githubUpdaterCredentials = get_option('github_updater_credentials', ['username' => '', 'token' => '']);

            if(empty($githubUpdaterPlugins)){ return; }
            if(empty($githubUpdaterCredentials['username'])){ return; }
            if(empty($githubUpdaterCredentials['token'])){ return; }
            if(empty($githubUpdaterPlugins[$_GET['id']])){ return; }

            $selectedPlugin = $githubUpdaterPlugins[$_GET['id']];

            $repo = $this->get_path_from_github_url($selectedPlugin['plugin_github_path']);

            $dest = $this->baseFolder.'/'.$selectedPlugin['plugin_name'];
            $this->rrmdir($dest);
            $zipPath = $this->download_latest($selectedPlugin['plugin_name'], $repo);
            mkdir($dest);
            $this->extract_folder($zipPath, $dest);

            $githubUpdaterPlugins[$_GET['id']]['last_sync'] = time();
            update_option('github_updater_plugins', $githubUpdaterPlugins);

            wp_redirect(site_url().'/wp-admin/admin.php?page=gu_admin');
        }

        if(!empty($_GET['action']) && $_GET['action'] == 'delete_plugin' && isset($_GET['id'])){

            $githubUpdaterPlugins = get_option('github_updater_plugins', []);

            if(empty($githubUpdaterPlugins)){ return; }
            if(empty($githubUpdaterPlugins[$_GET['id']])){ return; }

            unset($githubUpdaterPlugins[$_GET['id']]);
            $githubUpdaterPlugins = array_values($githubUpdaterPlugins);

            update_option('github_updater_plugins', $githubUpdaterPlugins);

            wp_redirect(site_url().'/wp-admin/admin.php?page=gu_admin');
        }
    }

    public function front_settings(){
        $githubUpdaterCredentials = get_option('github_updater_credentials', ['username' => '', 'token' => '']);
        ?>
        <h3>Identifiants api</h3>

        <form method="post">
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="username">Nom utilisateur compte github</label></th>
                        <td>
                            <input class="regular-text" id="username" type="text" name="username" value="<?= $githubUpdaterCredentials['username'] ?>">
                            <p class="description">Nom d'utilisateur utilisé pour se connecter à Github</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="token">Token</label></th>
                        <td>
                            <input class="regular-text" id="token" type="password" name="token" value="<?= $githubUpdaterCredentials['token'] ?>">
                            <p class="description">Token à générer ici : <a target="_blank" href="https://github.com/settings/tokens">access tokens</a></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <input class="button button-primary" type="submit" value="Enregistrer">
        </form>
        <?php
    }

    public function zip_folder($rootPath, $zipPath){
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $name => $file)
        {
            if (!$file->isDir()){
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }

    public function extract_folder($zipPath, $dest){
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === TRUE) {
            $zip->extractTo($dest);
            $zip->close();
        }else{
        }
    }

    public function get_subfolder_name($folder){
        $dirContent = scandir($folder);
        if(count($dirContent) == 3){
            return $folder.'/'.$dirContent[2];
        }
        return $folder;
    }

    public function download_latest($pluginName, $githubRepo){

        $githubUpdaterCredentials = get_option('github_updater_credentials', ['username' => '', 'token' => '']);

        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Authorization: Bearer ".$githubUpdaterCredentials['token']."\r\nUser-Agent: ".$githubUpdaterCredentials['username']
            ]
        ];

        $tempFolder = __DIR__.'/updates/'.$pluginName;
        $zipPath = __DIR__.'/updates/'.$pluginName.'.zip';

        $context = stream_context_create($opts);
        $file = file_get_contents('https://api.github.com/repos/'.$githubRepo.'/zipball/', false, $context);
        file_put_contents($zipPath, $file);

        mkdir($tempFolder);
        $this->extract_folder(__DIR__.'/updates/'.$pluginName.'.zip', $tempFolder);

        $newFolder = $this->get_subfolder_name($tempFolder);
        unlink($zipPath);
        $this->zip_folder($newFolder, $zipPath);
        $this->rrmdir($tempFolder);

        return $zipPath;
    }

    public function front_plugins(){
        $githubUpdaterCredentials = get_option('github_updater_credentials', ['username' => '', 'token' => '']);
        $githubUpdaterPlugins = get_option('github_updater_plugins', []);
        ?>
        <h3>Liste des plugins github</h3>
        <table class="wp-list-table widefat fixed striped pages" cellspacing="0">
            <thead class="table-light">
            <tr>
                <th class="manage-column column-author">Dossier du plugin</th>
                <th class="manage-column column-author">Chemin github</th>
                <th class="manage-column column-author">Version locale</th>
                <th class="manage-column column-author">Version Github</th>
                <th class="manage-column column-author">Dernière vérification</th>
                <th class="manage-column column-author">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($githubUpdaterPlugins as $id => $plugin) {
                echo '<tr>
                    <td>'.$plugin['plugin_name'].'</td>
                    <td>'.$plugin['plugin_github_path'].'</td>
                    <td>'.(empty($plugin['local_version']) ? '' : $plugin['local_version']).'</td>
                    <td>'.(empty($plugin['remote_version']) ? '' : $plugin['remote_version']).'</td>
                    <td>'.(empty($plugin['last_update_check']) ? '' : date('d/m/y H:i', $plugin['last_update_check'])).'</td>
                    <td>
                        <a class="btn btn-link" style="color:green" href="admin.php?page=gu_admin&action=update_plugin&id='.$id.'">Forcer mise à jour</a>&nbsp;&nbsp;&nbsp;
                        <a class="btn btn-link" onclick="return confirm(\'Etes vous sur ?\');" style="color:red" href="admin.php?page=gu_admin&action=delete_plugin&id='.$id.'">Supprimer</a>
                    </td>
                </tr>';
            }
            ?>
          </tbody>
        </table>
        <br>
        <h3>Ajouter un plugin github</h3>
        <form method="post">
            <input type="hidden" name="action" value="add">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="plugin_name">Nom du dossier du plugin</label></th>
                        <td>
                            <input class="regular-text" id="plugin_name" type="text" name="plugin_name" value="">
                            <p class="description">Nom exact du dossier du plugin à mettre à jour dans wp_content/plugins</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="plugin_github_path">Chemin github du plugin</label></th>
                        <td>
                            <input class="regular-text" id="plugin_github_path" type="text" name="plugin_github_path" value="">
                            <p class="description">Chemin du github (PROPRIETAIRE/REPO)</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <input class="button button-primary" type="submit" value="Ajouter">
        </form>

        <?php
    }

    public function get_path_from_github_url($url){
        $url = trim($url);
        $url = str_replace("https://github.com/", "", $url);
        $lastChar = substr($url, -1);
        if($lastChar == '/'){ $url = substr($url, 0, -1);   }
        return $url;
    }

    public function get_github_plugin_version($pluginName, $gitUrl){
        $githubUpdaterCredentials = get_option('github_updater_credentials', ['username' => '', 'token' => '']);

        $headers = [
            "Accept: application/vnd.github+json",
            "Authorization: Bearer ".$githubUpdaterCredentials['token'],
            "User-Agent: ".$githubUpdaterCredentials['username']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/'.$gitUrl.'/contents/'.$pluginName.'.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = json_decode(curl_exec($ch), true);

        if(empty($res)){ return; }

        $fileContent = base64_decode($res['content']);
        file_put_contents(__DIR__.'/data.php', $fileContent);
        $pluginData = get_plugin_data(__DIR__.'/data.php');
        unlink(__DIR__.'/data.php');

        return $pluginData;

        curl_close($ch);
    }

    public function admin_front(){
        ?>
        <div class="wrap">

            <h2>Parametres</h2>

            <?php
				if(isset($_GET[ 'tab' ])) { $active_tab = $_GET[ 'tab' ]; }
				if(empty($active_tab)){ $active_tab = 'form_options'; }
			?>
			<h2 class="nav-tab-wrapper">
				<a href="?page=<?= $_GET['page']; ?>&tab=form_options" class="nav-tab <?= $active_tab == 'form_options' ? 'nav-tab-active' : ''; ?>">Plugins</a>
				<a href="?page=<?= $_GET['page']; ?>&tab=credentials_options" class="nav-tab <?= $active_tab == 'credentials_options' ? 'nav-tab-active' : ''; ?>">Identifiants API</a>
			</h2>

			<?php
            if($active_tab == 'form_options'){
                $this->front_plugins();
			}
			if($active_tab == 'credentials_options'){
				$this->front_settings();
			}
			?>
        </div>
        <?php
    }
}

new GithubUpdater();
?>
