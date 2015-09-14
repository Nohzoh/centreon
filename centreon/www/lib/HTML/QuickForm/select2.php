<?php
/*
 * Copyright 2005-2015 Centreon
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give Centreon
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of Centreon choice, provided that
 * Centreon also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 *
 */

/**
 * Base class for form elements
 */ 
require_once 'HTML/QuickForm/select.php';

/**
 * Description of select2
 *
 * @author Lionel Assepo <lassepo@centreon.com>
 */
class HTML_QuickForm_select2 extends HTML_QuickForm_select
{
    /**
     *
     * @var type 
     */
    var $_elementHtmlName;
    
    /**
     *
     * @var type 
     */
    var $_elementTemplate;
    
    /**
     *
     * @var type 
     */
    var $_elementCSS;
    
    /**
     *
     * @var type 
     */
    var $_availableDatasetRoute;
    
    /**
     *
     * @var type 
     */
    var $_defaultDatasetRoute;
    
    /**
     *
     * @var type 
     */
    var $_ajaxSource;
    
    /**
     *
     * @var type 
     */
    var $_multiple;
    
    /**
     *
     * @var type 
     */
    var $_multipleHtml;
    
    /**
     *
     * @var type 
     */
    var $_defaultSelectedOptions;
    
    /**
     * 
     * @param string $elementName
     * @param string $elementLabel
     * @param array $options
     * @param array $attributes
     * @param string $sort
     */
    function HTML_QuickForm_select2(
        $elementName = null,
        $elementLabel = null,
        $options = null,
        $attributes = null,
        $sort = null
    ) {
        $this->_ajaxSource = false;
        $this->_defaultSelectedOptions = '';
        $this->_multipleHtml = '';
        $this->HTML_QuickForm_select($elementName, $elementLabel, $options, $attributes);
        $this->parseCustomAttributes($attributes);
    }
    
    /**
     * 
     * @param array $attributes
     */
    function parseCustomAttributes(&$attributes)
    {
        // Check for 
        if (isset($attributes['datasourceOrigin']) && ($attributes['datasourceOrigin'] == 'ajax')) {
            $this->_ajaxSource = true;
            // Check for 
            if (isset($attributes['availableDatasetRoute'])) {
                $this->_availableDatasetRoute = $attributes['availableDatasetRoute'];
            }
            
            // Check for 
            if (isset($attributes['defaultDatasetRoute'])) {
                $this->_defaultDatasetRoute = $attributes['defaultDatasetRoute'];
            }
        }
        
        if (isset($attributes['multiple']) && $attributes['multiple'] === true) {
            $this->_elementHtmlName = $this->getName() . '[]';
            $this->_multiple = true;
            $this->_multipleHtml = 'multiple="multiple"';
        } else {
            $this->_elementHtmlName = $this->getName();
            $this->_multiple = false;
        }
    }
    
    /**
     * 
     * @param boolean $raw
     * @param boolean $min
     * @return string
     */
    function getElementJs($raw = true, $min = false)
    {
        $jsFile = './include/common/javascript/jquery/plugins/select2/js/';
        
        if ($min) {
            $jsFile .= 'select2.min.js';
        } else {
            $jsFile .= 'select2.js';
        }
        
        $js = '<script type="text/javascript" '
            . 'src="' . $jsFile . '">'
            . '</script>';
        
        return $js;
    }
    
    /**
     * 
     * @return type
     */
    function getElementHtmlName()
    {
        return $this->_elementHtmlName;
    }
    
    /**
     * 
     * @param boolean $raw
     * @param boolean $min
     * @return string
     */
    function getElementCss($raw = true, $min = false)
    {
        $cssFile = './include/common/javascript/jquery/plugins/select2/css/';
        
        if ($min) {
            $cssFile .= 'select2.min.js';
        } else {
            $cssFile .= 'select2.js';
        }
        
        $css = '<link href="' . $cssFile . '" rel="stylesheet" type="text/css"/>';
        
        return $css;
    }
    
    /**
     * 
     * @return string
     */
    function toHtml()
    {
        $strHtml = '';
        
        if ($this->_flagFrozen) {
            $strHtml = $this->getFrozenHtml();
        } else {
            $strHtml = '<select id="' . $this->getName()
                . '" name="' . $this->getElementHtmlName()
                . '" ' . $this->_multipleHtml . ' '
                . ' style="width: 300px;">'
                . '%%DEFAULT_SELECTED_VALUES%%'
                . '</select>';
            $strHtml .= $this->getJsInit();
            $strHtml = str_replace('%%DEFAULT_SELECTED_VALUES%%', $this->_defaultSelectedOptions, $strHtml);
            
            file_put_contents('/tmp/Select2.html', $strHtml, FILE_APPEND);
        }
        
        return $strHtml;
    }
    
    /**
     * 
     * @return string
     */
    function getJsInit()
    {
        $jsPre = '<script type="text/javascript">';
        $jsPost = '</script>';
        $strJsInitBegining = 'jQuery("#' . $this->getName() . '").select2({';
        
        $mainJsInit = 'allowClear: true,';
        
        $label = $this->getLabel();
        if (empty($label)) {
            $mainJsInit .= 'placeholder: "' . $this->getLabel() . '",';
        }
        
        if ($this->_ajaxSource) {
            $mainJsInit .= $this->setAjaxSource() . ',';
        } else {
            $mainJsInit .= $this->setFixedDatas() . ',';
        }
        
        $mainJsInit .= 'multiple: ';
        if ($this->_multiple) {
            $mainJsInit .= 'true,';
        } else {
            $mainJsInit .= 'false,';
        }
        
        $strJsInitEnding = '});';
        
        $finalJs = $jsPre . $strJsInitBegining . $mainJsInit . $strJsInitEnding . $jsPost;
        
        return $finalJs;
    }
    
    public function AddDefaultValues()
    {
        
    }
    
    /**
     * 
     * @return string
     */
    public function setFixedDatas()
    {
        $datas = 'data: [';
        
        // Set default values
        $strValues = is_array($this->_values)? array_map('strval', $this->_values): array();
        /*if (count($strValues) > 0) {
            $strValues = implode(',', $strValues);
        }*/
        
        foreach ($this->_options as $option) {
            if (empty($option["attr"]["value"])) {
                $option["attr"]["value"] = -1;
            }
            $datas .= '{id: ' . $option["attr"]["value"] . ', text: "' . $option['text'] . '"},';
            
            if (!empty($strValues) && in_array($option['attr']['value'], $strValues, true)) {
                $option['attr']['selected'] = 'selected';
                $this->_defaultSelectedOptions .= "<option" . $this->_getAttrString($option['attr']) . '>' .
                        $option['text'] . "</option>";
            }
        }
        $datas .= ']';
        
        return $datas;
    }
    
    /**
     * 
     * @return string
     */
    public function setAjaxSource()
    {
        $ajaxInit = 'ajax: { ';
        $ajaxInit .= 'url: "' . $this->_availableDatasetRoute . '",';
        $ajaxInit .= '} ';
        return $ajaxInit;
    }
    
    /**
     * 
     * @return string
     */
    function getFrozenHtml()
    {
        $strFrozenHtml = '';
        return $strFrozenHtml;
    }
}

if (class_exists('HTML_QuickForm')) {
    HTML_QuickForm::registerElementType(
        'select2',
        'HTML/QuickForm/select2.php',
        'HTML_QuickForm_select2'
    );
}
