<?php
require_once 'vendor/pixel418/markdownify/src/Converter.php'; 
require_once 'vendor/pixel418/markdownify/src/ConverterExtra.php';
/**
 * A content editor plugin for Pico, using ContentTools.
 *
 * Supports PicoUsers plugin for authentification
 * {@link https://github.com/nliautaud/pico-users}
 * 
 * @author	Nicolas Liautaud
 * @link	https://github.com/nliautaud/pico-content-editor
 * @link    http://picocms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 0.2.3
 */
class PicoContentEditor extends AbstractPicoPlugin
{
    private $save = false;
    private $canSave = false;
    private $editedRegions = array();

    /**
     * Array of status logs that are returned in the JSON response
     * and used to display status messages on the client.
     *
     * @see PicoContentEditor::addStatus()
     * @var array
     */
    private $status = array();

    /**
     * HTML comment used in pages content to define the end of an editable block.
     * 
     * @see PicoContentEditor::onContentLoaded()
     * @see PicoContentEditor::getEditableRegions()
     */
    const ENDMARK = '<!--\s*end\s+editable\s*-->';




    
    /**
     * Enable php errors reporting when the debug setting is enabled,
     * look for PicoContentEditor save request and editing rights.
     * 
     * Triggered after Pico has read its configuration
     *
     * @see    Pico::getConfig()
     * @param  array &$config array of config variables
     * @return void
     */
    public function onConfigLoaded(array &$config)
    {
        if($this->getConfig('PicoContentEditor.debug')) {
            ini_set('display_startup_errors',1);
            ini_set('display_errors',1);
            error_reporting(-1); 
        }

        $this->save = isset($_POST['PicoContentEditor']);
        if ($this->save) {
            $this->setEditedRegions();
        }

        // check authentification with PicoUsers
        if (class_exists('PicoUsers')) {
            $PicoUsers = $this->getPlugin('PicoUsers');
            $this->canSave = $PicoUsers->hasRight('PicoContentEditor/save');
            if (!$this->canSave) {
                $this->addStatus(false, 'Authentification error');
            }
        }
    }
    /**
     * Look for edited regions in the page content and save them before
     * removing the end-editable mark. This function would be useless with
     * a better end-editable mark or a better parsin (see below).
     * 
     * The end-editable mark @see{PicoContentEditor::ENDMARK} need to be
     * striped away because it's somewhat breaking the page rendering,
     * and thus @see{PicoContentEditor::saveRegions()}  has to be done here
     * in addition to @see{PicoContentEditor::onPageRendered()}.
     * 
     * Triggered after Pico has read the contents of the file to serve
     *
     * @see    Pico::getRawContent()
     * @param  string &$rawContent raw file contents
     * @return void
     */
    public function onContentLoaded(&$rawContent)
    {
        // save edited regions
        if ($this->save && $this->canSave) {
            $this->saveRegions($rawContent);
        }
        // remove the end-editable mark
        $mark = self::ENDMARK;
        $rawContent = preg_replace("`$mark`", '', $rawContent);
    }
    /**
     * Register `{{ content_editor }}`, who outputs editor CSS and JS scripts.
     *
     * Triggered before Pico renders the page
     *
     * @see    Pico::getTwig()
     * @see    DummyPlugin::onPageRendered()
     * @param  Twig_Environment &$twig          twig template engine
     * @param  array            &$twigVariables template variables
     * @param  string           &$templateName  file name of the template
     * @return void
     */
    public function onPageRendering(Twig_Environment &$twig, array &$twigVariables, &$templateName)
    {
        $pluginurl = $this->getBaseUrl() . basename($this->getPluginsDir()) . '/PicoContentEditor';
        $twigVariables['content_editor'] = <<<EOF
        <link rel="stylesheet" type="text/css" href="$pluginurl/assets/contenttools/content-tools.min.css">
        <script src="$pluginurl/assets/contenttools/content-tools.min.js"></script>
        <script src="$pluginurl/assets/editor.js"></script>
EOF;
    }
    /**
     * If the call is a save query, save the edited regions and output the JSON response.
     *
     * Triggered after Pico has rendered the page
     *
     * @param  string &$output contents which will be sent to the user
     * @return void
     */
    public function onPageRendered(&$output)
    {
        if (!$this->save) return;
        
        // save regions from final output, so including blocks in templates.
        // page blocks have been saved in @see self::onContentLoaded
        if ($this->canSave) {
            $this->saveRegions($output);
        }
        
        // set final status
        $unsaved = array_filter( $this->editedRegions, function ($e) {
            return $e->saved == false;
        });
        if (count($unsaved)) {
            $this->addStatus(false, 'Not all regions have been saved');
        } else {
            $this->addStatus(true, 'All changes have been saved');
        }

        // output response
        $response = new stdClass();
        $response->status = $this->status;
        if ($this->getConfig('PicoContentEditor.debug')) {
            $response->regions = $this->editedRegions;
        }
        $output = json_encode($response);
    }





    /**
     * Adds a status entry.
     * 
     * @see PicoContentEditor::$status;
     * @param bool $state
     * @param string $message
     * @return void
     */
    private function addStatus($state, $message)
    {
        $this->status[] = (object) array(
            'state' => $state,
            'message' => $message
        );
    }
    /**
     * Set @see{PicoContentEditor::$editedRegions} according to data sent by the editor.
     *
     * @return void
     */
    private function setEditedRegions()
    {
        $regions = json_decode($_POST['PicoContentEditor']);
        foreach($regions as $name => $value) {
            $this->editedRegions[$name] = (object) array(
                'value' => $value,
                'saved' => false
            );
        }
    }
    /**
     * Look for editable blocks in the given string and save those who have been edited.
     *
     * @see PicoContentEditor::getEditableRegions()
     * @see PicoContentEditor::saveRegion()
     * @param string $content
     * @return void
     */
    private function saveRegions($content)
    {
        $regions = self::getEditableRegions($content);
        foreach ($regions as $region) {
            if (!isset($this->editedRegions[$region->name])) continue;
            $this->saveRegion($region, $this->editedRegions[$region->name]);
        }
    }
    /**
     * Return the list of editable blocks found in the given content.
     *
     * @param string $content
     * @return \stdClass before, name, content, after
     */
    private static function getEditableRegions($content)
    {
        $before = "data-editable\s+data-name\s*=\s*['\"]\s*(?P<name>[^'\"]*?)\s*['\"][^>]*>\s*\r?\n?";
        $mark = self::ENDMARK;
        $inner = '(?:(?!data-editable).)*?';
        $after = "\r?\n?\s*</[^>]+>\s*$mark";
        $pattern = "`(?P<before>$before)(?P<content>$inner)(?P<after>$after)`s";
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $key => $val) {
            $matches[$key] = (object) $val;
        }
        return $matches;
    }
    /**
     * Save a given region.
     *
     * @param \stdClass $region The editable block, @see{PicoContentEditor::getEditableRegions()}
     * @param \stdClass $editedRegion The edited region, @see{PicoContentEditor::$editedRegions}
     * @return void
     */
    private function saveRegion($region, &$editedRegion)
    {
        $isMd = preg_match("`markdown\s*=\s*['\"]?(?:1|true)['\"]?`", $region->before);
        $hasSrc = preg_match("`data-src\s*=\s*['\"]([^'\"]*?)['\"]`", $region->before, $src);

        // if required, convert edited content to markdown
        if ($isMd) {
            $converter = new Markdownify\ConverterExtra;
            $editedRegion->value = $converter->parseString($editedRegion->value);
        }
        
        // get the source file path as given by a src attribute, or the current page
        if ($hasSrc && !empty($src[1])) {
            $editedRegion->source = $this->getRootDir() . $src[1];
        } else $editedRegion->source = $this->getRequestFile();

        if (!file_exists($editedRegion->source)) {
            $editedRegion->error = 'Source file not found';
            return;
        }

        // load the source file and replace the block with new content
        $content = $this->loadFileContent($editedRegion->source);
        $content = str_replace(
            $region->before.$region->content.$region->after,
            $region->before.$editedRegion->value.$region->after,
            $content, $count);
        if (!$count) {
            $editedRegion->error = 'Error replacing region content';
            return;
        }

        // save the source file
        $editedRegion->saved = file_put_contents($editedRegion->source, $content);
        if (!$editedRegion->saved) {
            $editedRegion->error = 'Error writing file';
        }
    }
}

?>