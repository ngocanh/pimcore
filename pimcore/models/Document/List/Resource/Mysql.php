<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @category   Pimcore
 * @package    Document
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Document_List_Resource_Mysql extends Pimcore_Model_List_Resource_Mysql_Abstract {

    /**
     * Loads a list of objects (all are an instance of Document) for the given parameters an return them
     *
     * @return array
     */
    public function load() {

        $documents = array();
        $documentsData = $this->db->fetchAll("SELECT id,type FROM documents" . $this->getCondition() . $this->getOrder() . $this->getOffsetLimit());

        foreach ($documentsData as $documentData) {
            if($documentData["type"]) {
                $documents[] = Document::getById($documentData["id"]);
            }
        }

        $this->model->setDocuments($documents);
        return $documents;
    }

    protected function getCondition() {
        if ($cond = $this->model->getCondition()) {
            if (!Pimcore::inAdmin() && !$this->model->getUnpublished()) {
                return " WHERE (" . $cond . ") AND published = 1";
            }
            return " WHERE " . $cond . " ";
        }
        else if (!Pimcore::inAdmin() && !$this->model->getUnpublished()) {
            return " WHERE published = 1";
        }
        return "";
    }
    
    public function getTotalCount() {
        $amount = $this->db->fetchRow("SELECT COUNT(*) as amount FROM documents" . $this->getCondition());

        return $amount["amount"];
    }
}