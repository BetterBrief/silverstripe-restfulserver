<?php
/**
 * Generic RESTful server, which handles webservice access to arbitrary DataObjects.
 * Relies on serialization/deserialization into different formats provided
 * by the DataFormatter APIs in core.
 * 
 * @todo Finish RestfulServer_Item and RestfulServer_List implementation and re-enable $url_handlers
 * @todo Implement PUT/POST/DELETE for relations
 * @todo Access-Control for relations (you might be allowed to view Members and Groups, 
 *       but not their relation with each other)
 * @todo Make SearchContext specification customizeable for each class
 * @todo Allow for range-searches (e.g. on Created column)
 * @todo Filter relation listings by $api_access and canView() permissions
 * @todo Exclude relations when "fields" are specified through URL (they should be explicitly 
 *       requested in this case)
 * @todo Custom filters per DataObject subclass, e.g. to disallow showing unpublished pages in 
 * SiteTree/Versioned/Hierarchy
 * @todo URL parameter namespacing for search-fields, limit, fields, add_fields 
 *       (might all be valid dataobject properties)
 *       e.g. you wouldn't be able to search for a "limit" property on your subclass as 
 *       its overlayed with the search logic
 * @todo i18n integration (e.g. Page/1.xml?lang=de_DE)
 * @todo Access to extendable methods/relations like SiteTree/1/Versions or SiteTree/1/Version/22
 * @todo Respect $api_access array notation in search contexts
 * 
 * @package framework
 * @subpackage api
 */
class RestfulServer extends Controller {
	private static $url_handlers = array(
		'$ClassName/$ID/$ActionName' => 'handleAction',
		#'$ClassName/#ID' => 'handleItem',
		#'$ClassName' => 'handleList',
	);

	protected static $api_base = "api/v1/";

	protected static $authenticator = 'BasicRestfulAuthenticator';

	/**
	 * If no extension is given in the request, resolve to this extension
	 * (and subsequently the {@link self::$default_mimetype}.
	 *
	 * @var string
	 */
	public static $default_extension = "xml";
	
	/**
	 * If no extension is given, resolve the request to this mimetype.
	 *
	 * @var string
	 */
	protected static $default_mimetype = "text/xml";
	
	/**
	 * @uses authenticate()
	 * @var Member
	 */
	protected $member;
	
	private static $allowed_actions = array(
		'index',
	);
	
	/*
	function handleItem($request) {
		return new RestfulServer_Item(DataObject::get_by_id($request->param("ClassName"), $request->param("ID")));
	}

	function handleList($request) {
		return new RestfulServer_List(DataObject::get($request->param("ClassName"),""));
	}
	*/
	
	/**
	 * This handler acts as the switchboard for the controller.
	 * Since no $Action url-param is set, all requests are sent here.
	 */
	function index() {
		if(!isset($this->urlParams['ClassName'])) return $this->notFound();
		$className = $this->urlParams['ClassName'];
		$id = (isset($this->urlParams['ID'])) ? $this->urlParams['ID'] : null;
		$action = (isset($this->urlParams['ActionName'])) ? $this->urlParams['ActionName'] : null;
		
		// Check input formats
		if(!class_exists($className)) {
			return $this->notFound();
		}
		if($id && !(is_numeric($id) || $id == 'me')) {
			return $this->notFound();
		}
		if(
			$action
			&& !preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $action)
			) {
			return $this->notFound();
		}
		
		// if api access is disabled, don't proceed
		$apiAccess = singleton($className)->stat('api_access');
		if(!$apiAccess) {
			return $this->permissionFailure();
		}

		// authenticate
		$identity = $this->authenticate();
		// Must be a member as we use this with can*.
		if($identity instanceof Member) {
			$this->member = $identity;
		}
		else if($identity !== true) {
			return $this->permissionFailure();
		}

		// handle different HTTP verbs
		if($this->request->isGET() || $this->request->isHEAD()) {
			return $this->getHandler($className, $id, $action);
		}
		
		if($this->request->isPOST()) {
			return $this->postHandler($className, $id, $action);
		}
		
		if($this->request->isPUT()) {
			return $this->putHandler($className, $id, $action);
		}

		if($this->request->isDELETE()) {
			return $this->deleteHandler($className, $id, $action);
		}

		// if no HTTP verb matches, return error
		return $this->methodNotAllowed();
	}
	
	/**
	 * Handler for object read.
	 * 
	 * The data object will be returned in the following format:
	 *
	 * <ClassName>
	 *   <FieldName>Value</FieldName>
	 *   ...
	 *   <HasOneRelName id="ForeignID" href="LinkToForeignRecordInAPI" />
	 *   ...
	 *   <HasManyRelName>
	 *     <ForeignClass id="ForeignID" href="LinkToForeignRecordInAPI" />
	 *     <ForeignClass id="ForeignID" href="LinkToForeignRecordInAPI" />
	 *   </HasManyRelName>
	 *   ...
	 *   <ManyManyRelName>
	 *     <ForeignClass id="ForeignID" href="LinkToForeignRecordInAPI" />
	 *     <ForeignClass id="ForeignID" href="LinkToForeignRecordInAPI" />
	 *   </ManyManyRelName>
	 * </ClassName>
	 *
	 * Access is controlled by two variables:
	 * 
	 *   - static $api_access must be set. This enables the API on a class by class basis
	 *   - $obj->canView() must return true. This lets you implement record-level security
	 * 
	 * @todo Access checking
	 * 
	 * @param string $className
	 * @param int $id
	 * @param string $actionName The name of the action or relation name to execute, if any.
	 * @return string The serialized representation of the requested object(s) - usually XML or JSON.
	 */
	protected function getHandler($className, $id, $actionName) {
		$sort = '';
		
		if($this->request->getVar('sort')) {
			$dir = $this->request->getVar('dir');
			$sort = array($this->request->getVar('sort') => ($dir ? $dir : 'ASC'));
		}
		
		$limit = array(
			'start' => $this->request->getVar('start'),
			'limit' => $this->request->getVar('limit')
		);
		
		$params = $this->request->getVars();
		
		$remaining = explode('/', $this->request->remaining());
		foreach($remaining as $param) {
			$this->request->shift();
		}

		$responseFormatter = $this->getResponseDataFormatter($className);
		if(!$responseFormatter) {
			return $this->unsupportedMediaType();
		}
		
		// $obj can be either a DataObject or a SS_List,
		// depending on the request
		if($id) {
			// Format: /api/v1/<MyClass>/<ID>
			$obj = $this->getObjectQuery($className, $id, $params)->First();
			if(!$obj) {
				return $this->notFound();
			}
			if(!$obj->canView($this->member)) {
				return $this->permissionFailure();
			}

			// Format: /api/v1/<MyClass>/<ID>/<Action>
			if($actionName) {
				// check for additional methods on an API class
				$apiObj = $this->getAPIWrapper($obj, $actionName);

				if($apiObj) {
					$obj = $apiObj->handleAction($actionName, $this);
				}
				else {
					$obj = $this->getObjectRelationQuery($obj, $params, $sort, $limit, $actionName);
					if(!$obj) {
						return $this->notFound();
					}
				}
				
				// TODO Avoid creating data formatter again for relation class (see above)
				$responseFormatter = $this->getResponseDataFormatter($obj->dataClass());
			} 
			
		} else {
			// Format: /api/v1/<MyClass>
			$obj = $this->getObjectsQuery($className, $params, $sort, $limit);
		}
		
		$this->getResponse()->addHeader('Content-Type', $responseFormatter->getOutputContentType());
		
		$rawFields = $this->request->getVar('fields');
		$fields = $rawFields ? explode(',', $rawFields) : null;

		if($obj instanceof SS_List) {
			$responseFormatter->setTotalSize($obj->dataQuery()->query()->unlimitedRowCount());
			$objs = new ArrayList($obj->toArray());
			foreach($objs as $obj) {
				if(!$obj->canView($this->member)) {
					$objs->remove($obj);
				}
			}
			return $responseFormatter->convertDataObjectSet($objs, $fields);
		}
		else if(!$obj) {
			$responseFormatter->setTotalSize(0);
			return $responseFormatter->convertDataObjectSet(new ArrayList(), $fields);
		}
		else {
			return $responseFormatter->convertDataObject($obj, $fields);
		}
	}
	
	/**
	 * Uses the default {@link SearchContext} specified through
	 * {@link DataObject::getDefaultSearchContext()} to augument
	 * an existing query object (mostly a component query from {@link DataObject})
	 * with search clauses. 
	 * 
	 * @todo Allow specifying of different searchcontext getters on model-by-model basis
	 *
	 * @param string $className
	 * @param array $params
	 * @return SS_List
	 */
	protected function getSearchQuery($className, $params = null, $sort = null, 
		$limit = null, $existingQuery = null
	) {
		if(singleton($className)->hasMethod('getRestfulSearchContext')) {
			$searchContext = singleton($className)->{'getRestfulSearchContext'}();
		}
		else {
			$searchContext = singleton($className)->getDefaultSearchContext();
		}
		return $searchContext->getQuery($params, $sort, $limit, $existingQuery);
	}
	
	/**
	 * Returns a dataformatter instance based on the request
	 * extension or mimetype. Falls back to {@link self::$default_extension}.
	 * 
	 * @param boolean $includeAcceptHeader Determines wether to inspect and prioritize any HTTP Accept headers 
	 * @param String Classname of a DataObject
	 * @return DataFormatter
	 */
	protected function getDataFormatter($includeAcceptHeader = false, $className = null) {
		$extension = $this->request->getExtension();
		$contentTypeWithEncoding = $this->request->getHeader('Content-Type');
		$contentTypeMatches = array();
		preg_match('/([^;]*)/',$contentTypeWithEncoding, $contentTypeMatches);
		$contentType = $contentTypeMatches[0];
		$accept = $this->request->getHeader('Accept');
		$mimetypes = $this->request->getAcceptMimetypes();
		if(!$className) {
			$className = $this->urlParams['ClassName'];
		}

		// get formatter
		if(!empty($extension)) {
			$formatter = DataFormatter::for_extension($extension);
		}
		elseif($includeAcceptHeader && !empty($accept) && $accept != '*/*') {
			$formatter = DataFormatter::for_mimetypes($mimetypes);
			if(!$formatter) $formatter = DataFormatter::for_extension(self::$default_extension);
		}
		elseif(!empty($contentType)) {
			$formatter = DataFormatter::for_mimetype($contentType);
		}
		else {
			$formatter = DataFormatter::for_extension(self::$default_extension);
		}

		if(!$formatter) return false;
		
		// set custom fields
		if($customAddFields = $this->request->getVar('add_fields')) {
			$formatter->setCustomAddFields(explode(',',$customAddFields));
		}
		if($customFields = $this->request->getVar('fields')) {
			$formatter->setCustomFields(explode(',',$customFields));
		}
		$formatter->setCustomRelations($this->getAllowedRelations($className));
		
		$apiAccess = singleton($className)->stat('api_access');
		if(is_array($apiAccess)) {
			$formatter->setCustomAddFields(
				array_intersect((array)$formatter->getCustomAddFields(), (array)$apiAccess['view'])
			);
			if($formatter->getCustomFields()) {
				$formatter->setCustomFields(
					array_intersect((array)$formatter->getCustomFields(), (array)$apiAccess['view'])
				);
			}
			else {
				$formatter->setCustomFields((array)$apiAccess['view']);
			}
			if($formatter->getCustomRelations()) {
				$formatter->setCustomRelations(
					array_intersect((array)$formatter->getCustomRelations(), (array)$apiAccess['view'])
				);
			}
			else {
				$formatter->setCustomRelations((array)$apiAccess['view']);
			}
			
		}

		// set relation depth
		$relationDepth = $this->request->getVar('relationdepth');
		if(is_numeric($relationDepth)) {
			$formatter->relationDepth = (int)$relationDepth;
		}
		
		return $formatter;		
	}
	
	/**
	 * @param String Classname of a DataObject
	 * @return DataFormatter
	 */
	protected function getRequestDataFormatter($className = null) {
		return $this->getDataFormatter(false, $className);
	}
	
	/**
	 * @param String Classname of a DataObject
	 * @return DataFormatter
	 */
	protected function getResponseDataFormatter($className = null) {
		return $this->getDataFormatter(true, $className);
	}
	
	/**
	 * Handler for object delete
	 */
	protected function deleteHandler($className, $id) {
		$obj = $this->getObjectFromParams($className, $id);
		if(!$obj) {
			return $this->notFound();
		}
		if(!$obj->canDelete($this->member)) {
			return $this->permissionFailure();
		}
		
		$obj->delete();
		
		$this->getResponse()->setStatusCode(204); // No Content
		return true;
	}

	/**
	 * Handler for object write
	 */
	protected function putHandler($className, $id) {
		$obj = $this->getObjectFromParams($className, $id);
		if(!$obj) {
			return $this->notFound();
		}
		if(!$obj->canEdit($this->member)) {
			return $this->permissionFailure();
		}
		
		$reqFormatter = $this->getRequestDataFormatter($className);
		if(!$reqFormatter) {
			return $this->unsupportedMediaType();
		}
		
		$responseFormatter = $this->getResponseDataFormatter($className);
		if(!$responseFormatter) {
			return $this->unsupportedMediaType();
		}
		
		$obj = $this->updateDataObject($obj, $reqFormatter);
		
		$this->getResponse()->setStatusCode(200); // Success
		$this->getResponse()->addHeader('Content-Type', $responseFormatter->getOutputContentType());

		// Append the default extension for the output format to the Location header
		// or else we'll use the default (XML)
		$types = $responseFormatter->supportedExtensions();
		$type = '';
		if (count($types)) {
			$type = ".{$types[0]}";
		}

		$objHref = Director::absoluteURL(self::$api_base . "$obj->class/$obj->ID" . $type);
		$this->getResponse()->addHeader('Location', $objHref);
		
		return $responseFormatter->convertDataObject($obj);
	}

	/**
	 * Handler for object append / method call.
	 * 
	 * @todo Posting to an existing URL (without a relation)
	 * current resolves in creatig a new element,
	 * rather than a "Conflict" message.
	 */
	protected function postHandler($className, $id, $actionName) {
		if($id) {
			if(!$actionName) {
				$this->response->setStatusCode(409);
				return 'Conflict';
			}
			
			$obj = $this->getObjectFromParams($className, $id);
			if(!$obj) {
				return $this->notFound();
			}

			// check for additional methods on an API class
			$apiObj = $this->getAPIWrapper($obj, $actionName);
			$returnBody = false;

			if($apiObj) {
				$result = $apiObj->handleAction($actionName, $this);
			}
			else if($obj->hasMethod($actionName)) {
				Deprecation::notice('3.2', 'Extend RestfulServer_API for custom API methods');
				if(!$obj->stat('allowed_actions') || !in_array($actionName, $obj->stat('allowed_actions'))) {
					return $this->permissionFailure();
				}
				$obj->$actionName();
				// Set to null as previous versions didn't return a value and
				// thus blindly upgrading may result in unwanted data exposure.
				$result = null;
			}
			else {
				return $this->notFound();
			}
			
			$returnBody = isset($result);

			if(!$returnBody) {
				$this->getResponse()->setStatusCode(204); // No Content
				return '';
			}
			else {
				$responseFormatter = $this->getResponseDataFormatter($className);
				$this->getResponse()->addHeader('Content-Type', $responseFormatter->getOutputContentType());
				// Handle validation errors
				if($result instanceof ValidationResult) {
					return $this->handleValidationError($result, $obj, $responseFormatter);
				}
				else if($result instanceof RestfulServer_API) {
					$result = $result->getData();
				}
				return $responseFormatter->convertDataObject($result);

			}
		}
		else {
			if(!singleton($className)->canCreate($this->member)) {
				return $this->permissionFailure();
			}
			$obj = new $className();
		
			$reqFormatter = $this->getRequestDataFormatter($className);
			if(!$reqFormatter) {
				return $this->unsupportedMediaType();
			}
		
			$responseFormatter = $this->getResponseDataFormatter($className);
		
			$result = $this->updateDataObject($obj, $reqFormatter);
		
			// If the object is nothing, then it is a 204. Return no content.
			if($result instanceof DataObject && !$result->exists()) {
				return '';
			}

			$this->getResponse()->addHeader('Content-Type', $responseFormatter->getOutputContentType());
			
			// Handle validation errors
			if($result instanceof ValidationResult) {
				return $this->handleValidationError($result, $obj, $reqFormatter);
			}

			$this->getResponse()->setStatusCode(201); // Created

			// Append the default extension for the output format to the Location header
			// or else we'll use the default (XML)
			$types = $responseFormatter->supportedExtensions();
			$type = '';
			if (count($types)) {
				$type = ".{$types[0]}";
			}

			$objHref = Director::absoluteURL(self::$api_base . "$result->class/$result->ID" . $type);
			$this->getResponse()->addHeader('Location', $objHref);
		
			return $responseFormatter->convertDataObject($result);
		}
	}
	
	/**
	 * Converts either the given HTTP Body into an array
	 * (based on the DataFormatter instance), or returns
	 * the POST variables.
	 * Automatically filters out certain critical fields
	 * that shouldn't be set by the client (e.g. ID).
	 *
	 * @param DataObject $obj
	 * @param DataFormatter $formatter
	 * @return mixed DataObject The passed object, or a ValidationResult
	 */
	protected function updateDataObject($obj, $formatter) {
		// if neither an http body nor POST data is present, return error
		$body = $this->request->getBody();
		if(!$body && !$this->request->postVars()) {
			$this->getResponse()->setStatusCode(204); // No Content
			return $obj;
		}
		
		if(!empty($body)) {
			$data = $this->getPayloadArray();
		}
		else {
			// assume application/x-www-form-urlencoded which is automatically parsed by PHP
			$data = $this->request->postVars();
		}
		
		// @todo Disallow editing of certain keys in database
		$data = array_diff_key($data, array('ID','Created'));
		
		$apiAccess = singleton($this->urlParams['ClassName'])->stat('api_access');
		if(is_array($apiAccess) && isset($apiAccess['edit'])) {
			$data = array_intersect_key($data, array_combine($apiAccess['edit'],$apiAccess['edit']));
		}

		$obj->update($data);

		$result = $obj->validate();
		if(!$result->valid()) {
			$this->getResponse()->setStatusCode(400);
			return $result;
		}

		$obj->write();
		
		return $obj;
	}
	
	/**
	 * Handles validation errors and formats them properly.
	 * @param ValidationResult $result
	 * @param DataObject $object
	 * @param DataFormatter $formatter
	 * @return string
	 */
	public function handleValidationError($result, $object, $formatter) {
		$this->getResponse()->setStatusCode(400);
		return $formatter->convertValidationResult($result);
	}

	/**
	 * @return RestfulServer_API|false
	 */
	public function getAPIWrapper($obj, $actionName) {
		$apiClass = $obj->class . '_API';
		if(!class_exists($apiClass)) {
			return false;
		}
		$apiObj = new $apiClass($obj, $this);
		if(!$apiObj->hasMethod($actionName)) {
			return false;
		}
		return $apiObj;
	}

	/**
	 * Returns the parsed request payload in array form.
	 * @return array
	 */
	public function getPayloadArray() {
		$formatter = $this->getDataFormatter();
		return $formatter->convertStringToArray($this->request->getBody());
	}

	/**
	 * Gets a DataObject, typically from the request ClassName and ID params
	 * @return DataObject|false
	 */
	public function getObjectFromParams($className, $id) {
		if($className == 'Member' && $id == 'me') {
			return $this->member;
		}
		return $className::get()->byId($id);
	}

	/**
	 * Gets a single DataObject by ID,
	 * through a request like /api/v1/<MyClass>/<MyID>
	 * 
	 * @param string $className
	 * @param int $id
	 * @param array $params
	 * @return DataList
	 */
	protected function getObjectQuery($className, $id, $params) {
	    return DataList::create($className)->byIDs(array($id));
	}
	
	/**
	 * @param DataObject $obj
	 * @param array $params
	 * @param int|array $sort
	 * @param int|array $limit
	 * @return SQLQuery
	 */
	protected function getObjectsQuery($className, $params, $sort, $limit) {
		return $this->getSearchQuery($className, $params, $sort, $limit);
	}
	
	
	/**
	 * @param DataObject $obj
	 * @param array $params
	 * @param int|array $sort
	 * @param int|array $limit
	 * @param string $relationName
	 * @return SQLQuery|boolean
	 */
	protected function getObjectRelationQuery($obj, $params, $sort, $limit, $relationName) {
		// The relation method will return a DataList, that getSearchQuery subsequently manipulates
		if($obj->hasMethod($relationName)) {
			if($relationClass = $obj->has_one($relationName)) {
				$joinField = $relationName . 'ID';
				$list = DataList::create($relationClass)->byIDs(array($obj->$joinField));
			}
			else {
				$list = $obj->$relationName();
			}
			
			$apiAccess = singleton($list->dataClass())->stat('api_access');
			if(!$apiAccess) {
				return false;
			}
			
			return $this->getSearchQuery($list->dataClass(), $params, $sort, $limit, $list);
		}
	}
	
	protected function permissionFailure() {
		$authClass = self::config()->authenticator;
		// always return a 401
		$this->getResponse()->setStatusCode(401);
		$this->getResponse()->addHeader('Content-Type', 'text/plain');
		// Let the authenticator augment any extra info on the response
		if(method_exists($authClass, 'permissionFailure')) {
			$authClass::permissionFailure($this->response);
		}
		return "You don't have access to this item through the API.";
	}

	protected function notFound() {
		// return a 404
		$this->getResponse()->setStatusCode(404);
		$this->getResponse()->addHeader('Content-Type', 'text/plain');
		return "That object wasn't found";
	}
	
	protected function methodNotAllowed() {
		$this->getResponse()->setStatusCode(405);
		$this->getResponse()->addHeader('Content-Type', 'text/plain');
		return "Method Not Allowed";
	}
	
	protected function unsupportedMediaType() {
		$this->response->setStatusCode(415); // Unsupported Media Type
		$this->getResponse()->addHeader('Content-Type', 'text/plain');
		return "Unsupported Media Type";
	}
	
	/**
	 * A function to authenticate a user
	 *
	 * @return Member|false the logged in member
	 */
	protected function authenticate() {
		$authClass = self::config()->authenticator;
		return $authClass::authenticate();
	}
	
	/**
	 * Return only relations which have $api_access enabled.
	 * @todo Respect field level permissions once they are available in core
	 * 
	 * @param string $class
	 * @param Member $member
	 * @return array
	 */
	protected function getAllowedRelations($class, $member = null) {
		$allowedRelations = array();
		$obj = singleton($class);
		$relations = (array)$obj->has_one() + (array)$obj->has_many() + (array)$obj->many_many();
		if($relations) foreach($relations as $relName => $relClass) {
			if(singleton($relClass)->stat('api_access')) {
				$allowedRelations[] = $relName;
			}
		}
		return $allowedRelations;
	}
	
}

/**
 * Restful server handler for a SS_List
 * 
 * @package framework
 * @subpackage api
 */
class RestfulServer_List {
	static $url_handlers = array(
		'#ID' => 'handleItem',
	);

	function __construct($list) {
		$this->list = $list;
	}
	
	function handleItem($request) {
		return new RestulServer_Item($this->list->getById($request->param('ID')));
	}
}

/**
 * Restful server handler for a single DataObject
 * 
 * @package framework
 * @subpackage api
 */
class RestfulServer_Item {
	static $url_handlers = array(
		'$Relation' => 'handleRelation',
	);

	function __construct($item) {
		$this->item = $item;
	}
	
	function handleRelation($request) {
		$funcName = $request('Relation');
		$relation = $this->item->$funcName();

		if($relation instanceof SS_List) {
			return new RestfulServer_List($relation);
		}
		else {
			return new RestfulServer_Item($relation);
		}
	}
}
