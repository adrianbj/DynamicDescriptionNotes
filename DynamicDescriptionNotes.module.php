<?php

/**
* Lets you insert PW variables and Hanna codes in Description and Note fields.
*
* Specifiy fields/properties of the page, eg:
* [page.parent.url]
* [page.title]
* [page.template.label]
*
* You can also define a str_replace to be performed on the returned value, eg:
* [page.name.(-|_)]
* which will return the page name with the dashes replaced with underscores
*
* You can also use hanna codes within your description and notes fields - big thanks to @Robin S for this idea
*
*/

class DynamicDescriptionNotes extends WireData implements Module, ConfigurableModule {

    public static function getModuleInfo() {
        return array(
            'title' => 'Dynamic Description & Notes',
            'version' => '0.1.2',
            'summary' => 'Lets you insert PW variables, HTML, and Hanna codes in Description and Note fields.',
            'autoload' => "template=admin",
            );
    }

    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();
    private $hanna;
    private $p;

   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
            return array(
                "allowHtml" => null
            );
    }

    /**
     * Populate the default config data
     *
     */
    public function __construct() {
       foreach(self::getDefaultData() as $key => $value) {
               $this->$key = $value;
       }
    }


    public function init() {
        $this->addHookBefore('Inputfield::render', $this, 'replaceVariables');
    }

    protected function replaceVariables(HookEvent $event) {

        $process = $this->wire('process');
        if($process->className() !== 'ProcessPageEdit' && $process->className() !== 'ProcessUser') return;
        if(!$this->p) $this->p = $process->getPage();

        $inputfield = $event->object;
        if($this->data['allowHtml']) $inputfield->entityEncodeText = false; // turn of entity encoding so we can have HTML
        $field = $this->fields->get($event->object->name);

        $description = $inputfield->description;
        $notes = $inputfield->notes;
        if($description == '' && $notes == '') return;

        if($description !== '') $inputfield->description = $this->allReplacements($description, $this->p, $field);
        if($notes !== '') $inputfield->notes  = $this->allReplacements($notes, $this->p, $field);

    }

    private function allReplacements($text, $p, $field) {
        $text = $this->variablesReplace($text, $p);
        if($this->modules->isInstalled('TextformatterHannaCode')) $text = $this->hannaFormat($text, $p, $field);
        return $this->data['allowHtml'] ? $this->wire('sanitizer')->purify($text) : $this->wire('sanitizer')->entitiesMarkdown($text);
    }

    private function variablesReplace($text, $p) {

        if(strpos($text, '[page.') === false) return $text;
        // the "\p{L}age" instead of simply "page" prevents this error in some versions of PHP
        // Error: preg_match_all(): Compilation failed: unknown property name after \P or \p
        preg_match_all('/\[\p{L}age([^\]]*)\]/', $text, $matches);
        foreach($matches[1] as $match) {
            $i=0;
            $properties = array();
            foreach(explode('.', $match) as $property) {
                // if property is a defined str_replacement in parentheses set $strReplace
                if(strpos($property, '(') !== false) {
                    preg_match_all("/\((.*?)\)/", $property, $strReplace);
                }
                //else it's a real property
                elseif($i>0) {
                    $strReplace = null;
                    $properties[] = $property;
                }
                $i++;
            }
            // get the value of the defined PW page properties
            $replacement = eval('return $p->' . implode('->', $properties) . ';');

            // defined $strReplace changes to the value of the PW page property
            if(isset($strReplace)) {
                $parts = explode('|', $strReplace[1][0]);
                $replacement = str_replace($parts[0], $parts[1], $replacement);
            }
            $text = str_replace('[page'.$match.']', $replacement, $text);
        }
        return $text;
    }

    private function hannaFormat($text, $p, $field) {
        if(!$this->hanna) $this->hanna = $this->modules->get('TextformatterHannaCode');
        if(strpos($text, $this->hanna->openTag) !== false && strpos($text, $this->hanna->closeTag) !== false) {
            return $this->hanna->render($text, $p, $field);
        }
        else {
            return $text;
        }
    }

    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $data = array_merge(self::getDefaultData(), $data);

        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'allowHtml');
        $f->label = __('Allow HTML', __FILE__);
        $f->description = __('Check this to allow HTML in description and note fields.', __FILE__);
        $f->attr('checked', $data['allowHtml'] == '1' ? 'checked' : '' );
        $wrapper->add($f);

        return $wrapper;
    }

}
