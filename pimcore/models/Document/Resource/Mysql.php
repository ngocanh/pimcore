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

class Document_Resource_Mysql extends Element_Resource_Mysql {

    /**
     * Contains the valid database colums
     *
     * @var array
     */
    protected $validColumnsDocument = array();

    /**
     * Get the valid database columns from database
     *
     * @return void
     */
    public function init() {
        $this->validColumnsDocument = $this->getValidTableColumns("documents");
    }

    /**
     * Get the data for the object by the given id
     *
     * @param integer $id
     * @return void
     */
    public function getById($id) {
        try {
            $data = $this->db->fetchRow("SELECT * FROM documents WHERE id = ?", $id);
        }
        catch (Exception $e) {
        }

        if ($data["id"] > 0) {
            $this->assignVariablesToModel($data);
        }
        else {
            throw new Exception("Document with the ID " . $id . " doesn't exists");
        }
    }

    /**
     * Get the data for the object by the given path
     *
     * @param string $path
     * @return void
     */
    public function getByPath($path) {
        try {
            // remove trailing slash if exists
            if (substr($path, -1) == "/" and strlen($path) > 1) {
                $path = substr($path, 0, count($path) - 2);
            }
            $data = $this->db->fetchRow("SELECT * FROM documents WHERE CONCAT(path,`key`) = '" . $path . "'");

            if ($data["id"]) {
                $this->assignVariablesToModel($data);
            }
            else {
                throw new Exception("document doesn't exist");
            }
        }
        catch (Exception $e) {
            throw $e;
        }

    }

    /**
     * Create a new record for the object in the database
     *
     * @return void
     */
    public function create() {


        try {


            $this->db->insert("documents", array(
                "path" => $this->model->getPath(),
                "parentId" => $this->model->getParentId()
            ));

            $date = time();
            $this->model->setId($this->db->lastInsertId());

            if (!$this->model->getKey()) {
                $this->model->setKey($this->model->getId());
            }

            $this->model->setCreationDate($date);
            $this->model->setModificationDate($date);

        }
        catch (Exception $e) {
            throw $e;
        }

    }

    /**
     * Updates the object's data to the database, it's an good idea to use save() instead
     *
     * @return void
     */
    public function update() {
        try {
            $this->model->setModificationDate(time());

            $document = get_object_vars($this->model);

            foreach ($document as $key => $value) {
                if (in_array($key, $this->validColumnsDocument)) {
                    if (is_bool($value)) {
                        $value = (int) $value;
                    }
                    $data[$key] = $value;
                }
            }

            // first try to insert a new record, this is because of the recyclebin restore
            try {
                $this->db->insert("documents", $data);
            }
            catch (Exception $e) {
                $this->db->update("documents", $data, "id='" . $this->model->getId() . "'");
            }
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Deletes the object from database
     *
     * @return void
     */
    public function delete() {
        try {
            $this->db->delete("documents", "id='" . $this->model->getId() . "'");
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    public function updateChildsPaths($oldPath) {
        //get documents to empty their cache
        $documents = $this->db->fetchAll("SELECT id,path FROM documents WHERE path LIKE '" . $oldPath . "%'");

        //update documents child paths
        $this->db->exec("update documents set path = replace(path,'" . $oldPath . "','" . $this->model->getFullPath() . "') where path like '" . $oldPath . "/%';");

        //update documents child permission paths
        $this->db->exec("update documents_permissions set cpath = replace(cpath,'" . $oldPath . "','" . $this->model->getFullPath() . "') where cpath like '" . $oldPath . "/%';");

        //update documents child properties paths
        $this->db->exec("update properties set cpath = replace(cpath,'" . $oldPath . "','" . $this->model->getFullPath() . "') where cpath like '" . $oldPath . "/%';");


        foreach ($documents as $document) {
            // empty documents cache
            try {
                Pimcore_Model_Cache::clearTag("document_" . $document["id"]);
            }
            catch (Exception $e) {
            }
        }

    }

    /**
     * @return string retrieves the current full document path from DB
     */
    public function getCurrentFullPath() {
        try {
            $data = $this->db->fetchRow("SELECT CONCAT(path,`key`) as path FROM documents WHERE id = ?", $this->model->getId());
        }
        catch (Exception $e) {
            Logger::error(get_class($this) . ": could not get current document path from DB");

        }


        return $data['path'];

    }


    /**
     * Get the properties for the object from database and assign it
     *
     * @return void
     */
    public function getProperties($onlyInherited = false) {

        $properties = array();

        $pathParts = explode("/", $this->model->getRealFullPath());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "cpath = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "cpath = '/" . implode("/", $tmpPathes) . "'";
        }

        $pathCondition = implode(" OR ", $pathConditionParts);

        $propertiesRaw = $this->db->fetchAll("SELECT * FROM properties WHERE (((" . $pathCondition . ") AND inheritable = 1) OR cid = '" . $this->model->getId() . "')  AND ctype='document' ORDER BY cpath ASC");

        foreach ($propertiesRaw as $propertyRaw) {

            try {
                $property = new Property();
                $property->setType($propertyRaw["type"]);
                $property->setCid($this->model->getId());
                $property->setName($propertyRaw["name"]);
                $property->setCtype("document");
                $property->setDataFromResource($propertyRaw["data"]);
                $property->setInherited(true);
                if ($propertyRaw["cid"] == $this->model->getId()) {
                    $property->setInherited(false);
                }
                $property->setInheritable(false);
                if ($propertyRaw["inheritable"]) {
                    $property->setInheritable(true);
                }
                
                if($onlyInherited && !$property->getInherited()) {
                    continue;
                }
                
                $properties[$propertyRaw["name"]] = $property;
            }
            catch (Exception $e) {
                Logger::error("can't add property " . $propertyRaw["name"] . " to document " . $this->model->getRealFullPath());
            }
        }
        
        // if only inherited then only return it and dont call the setter in the model
        if($onlyInherited) {
            return $properties;
        }
        
        $this->model->setProperties($properties);

        return $properties;
    }

    /**
     * deletes all properties for the object from database
     *
     * @return void
     */
    public function deleteAllProperties() {
        $this->db->delete("properties", "cid = '" . $this->model->getId() . "' AND ctype = 'document'");
    }

    /**
     * get recursivly the permissions for the passed user
     *
     * @param User $user
     * @return Document_Permission
     */
    public function getPermissionsForUser(User $user) {
        $pathParts = explode("/", $this->model->getRealFullPath());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "cpath = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "cpath = '/" . implode("/", $tmpPathes) . "'";
        }
        $pathCondition = implode(" OR ", $pathConditionParts);
        $permissionRaw = $this->db->fetchRow("SELECT id FROM documents_permissions WHERE (" . $pathCondition . ") AND userId='" . $user->getId() . "' ORDER BY cpath DESC LIMIT 1");

        //path condition for parent document
        $parentDocumentPathParts = array_slice($pathParts, 0, -1);
        $parentDocumentPathConditionParts[] = "cpath = '/'";
        foreach ($parentDocumentPathParts as $parentDocumentPathPart) {
            $parentDocumentTmpPaths[] = $parentDocumentPathPart;
            $parentDocumentPathConditionParts[] = "cpath = '/" . implode("/", $parentDocumentTmpPaths) . "'";
        }
        $parentDocumentPathCondition = implode(" OR ", $parentDocumentPathConditionParts);
        $parentDocumentPermissionRaw = $this->db->fetchRow("SELECT id FROM documents_permissions WHERE (" . $parentDocumentPathCondition . ") AND userId='" . $user->getId() . "' ORDER BY cpath DESC LIMIT 1");
        $parentDocumentPermissions = new Document_Permissions();
        if ($parentDocumentPermissionRaw["id"]) {
            $parentDocumentPermissions = Document_Permissions::getById($parentDocumentPermissionRaw["id"]);
        }

        $parentUser = $user->getParent();
        if ($parentUser instanceof User and $parentUser->isAllowed("documents")) {
            $parentPermission = $this->getPermissionsForUser($parentUser);
        } else $parentPermission = null;

        $permission = new Document_Permissions();
        if ($permissionRaw["id"] and $parentPermission instanceof Document_Permissions) {

            //consider user group permissions
            $permission = Document_Permissions::getById($permissionRaw["id"]);
            $permissionKeys = $permission->getValidPermissionKeys();

            foreach ($permissionKeys as $key) {
                $getter = "get" . ucfirst($key);
                $setter = "set" . ucfirst($key);

                if ((!$permission->getList() and !$parentPermission->getList()) or  !$parentDocumentPermissions->getList()) {
                    //no list - return false for all
                    $permission->$setter(false);
                } else if ($parentPermission->$getter()) {
                    //if user group allows -> return true, it overrides the user permission!
                    $permission->$setter(true);
                }
            }


        } else if ($permissionRaw["id"]) {
            //use user permissions, no user group to override anything
            $permission = Document_Permissions::getById($permissionRaw["id"]);

            //check parent document's list permission
            if (!$parentDocumentPermissions->getList() or !$permission->getList()) {
                $permissionKeys = $permission->getValidPermissionKeys();
                foreach ($permissionKeys as $key) {
                    $setter = "set" . ucfirst($key);
                    $permission->$setter(false);
                }
            }

        } else if ($parentPermission instanceof Document_Permissions and $parentPermission->getId() > 0) {
            //use user group permissions - no permission found for user at all
            $permission = $parentPermission;
            //check parent document's list permission
            if (!$parentDocumentPermissions->getList() or !$permission->getList()) {
                $permissionKeys = $permission->getValidPermissionKeys();
                foreach ($permissionKeys as $key) {
                    $setter = "set" . ucfirst($key);
                    $permission->$setter(false);
                }
            }

        } else {
            //neither user group nor user has permissions set -> use default all allowed
            $permission->setUser($user);
            $permission->setUserId($user->getId());
            $permission->setUsername($user->getUsername());
            $permission->setCid($this->model->getId());
            $permission->setCpath($this->model->getFullPath());

        }


        $this->model->setUserPermissions($permission);

        return $permission;
    }

    /**
     * @return array
     */

    public function getPermissions() {

        $permissions = array();

        $permissionsRaw = $this->db->fetchAll("SELECT id FROM documents_permissions WHERE cid='" . $this->model->getId() . "' ORDER BY cpath ASC");

        $userIdMappings = array();
        foreach ($permissionsRaw as $permissionRaw) {
            $permissions[] = Document_Permissions::getById($permissionRaw["id"]);
        }


        $this->model->setPermissions($permissions);

        return $permissions;
    }

    /**
     * @return void
     */
    public function deleteAllPermissions() {
        $this->db->delete("documents_permissions", "cid='" . $this->model->getId() . "'");
    }

    /**
     * @return void
     */
    public function deleteAllTasks() {
        $this->db->delete("schedule_tasks", "cid='" . $this->model->getId() . "' AND ctype='document'");
    }

    /**
     * Quick test if there are childs
     *
     * @return boolean
     */
    public function hasChilds() {
        $c = $this->db->fetchRow("SELECT id FROM documents WHERE parentId = '" . $this->model->getId() . "'");

        $state = false;
        if ($c["id"]) {
            $state = true;
        }

        $this->model->hasChilds = $state;

        return $state;
    }

    /**
     * returns the amount of directly childs (not recursivly)
     *
     * @return integer
     */
    public function getChildAmount() {
        $c = $this->db->fetchRow("SELECT COUNT(*) AS count FROM documents WHERE parentId = '" . $this->model->getId() . "'");
        return $c["count"];
    }
    
    public function isLocked () {
        
        // check for an locked element below this element
        $belowLocks = $this->db->fetchRow("SELECT id FROM documents WHERE path LIKE '".$this->model->getFullpath()."%' AND locked IS NOT NULL AND locked != '';");
        
        if(is_array($belowLocks) && count($belowLocks) > 0) {
            return true;
        }
        
        // check for an inherited lock
        $pathParts = explode("/", $this->model->getFullPath());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "CONCAT(path,`key`) = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "CONCAT(path,`key`) = '/" . implode("/", $tmpPathes) . "'";
        }

        $pathCondition = implode(" OR ", $pathConditionParts);
        $inhertitedLocks = $this->db->fetchAll("SELECT id FROM documents WHERE (" . $pathCondition . ") AND locked = 'propagate';");
        
        if(is_array($inhertitedLocks) && count($inhertitedLocks) > 0) {
            return true;
        }
        
        
        return false;
    }

}