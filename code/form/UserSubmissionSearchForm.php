<?php

class UserSubmissionSearchFormValidator extends RequiredFields {
	public function php($data) {
		$valid = parent::php($data);
		return $valid;
	}
}

class UserSubmissionSearchForm extends Form {
	private static $dropdown_empty_string = '- Please select -';

	private static $submit_use_button_tag = true;

	private static $submit_classes = 'button btn';

	protected $formMethod = 'get';

	public function __construct($controller, $name) {
		$this->controller = $controller;
		$userSubmissionHolder = $this->controller->data();
		$editableFormFields = $userSubmissionHolder->Fields()->filter(array('EnableOnSearchForm' => true));

		$fields = new FieldList();
		foreach ($editableFormFields as $editableFormField)
		{
			$field = $this->getFormFieldFromEditableFormField($editableFormField);
			if ($field)
			{
				$fields->push($field);
			}
		}

		$willCreateKeywordsField = $userSubmissionHolder->Fields()->find('UseInKeywordSearch', true);
		if ($willCreateKeywordsField) {
			$fields->unshift($field = TextField::create('Keywords', 'Keywords'));
		}

		$actions = new FieldList(
            $submitButton = FormAction::create('doSearch', 'Search')
                ->setUseButtonTag($this->config()->submit_use_button_tag)
                ->addExtraClass($this->config()->submit_classes)
        );

        $validator = UserSubmissionSearchFormValidator::create();
        $validator->fields = $fields;

		parent::__construct($controller, $name, $fields, $actions, $validator);

		$this->disableSecurityToken();

		// Retain search text and selections for pagination pages 
		// (as pagination doesn't trigger the httpSubmission function)
		$this->loadDataFrom($controller->getRequest()->requestVars());

		// note(Jake): Probably the perfect way to extend the form. Leaving commented until real use case arises.
		//$this->extend('updateForm');
	}

	/**
	 * Check if the search action has been set.
	 *
	 * @var boolean
	 */
	public function getHasSearched() {
		return isset($_REQUEST['action_doSearch']);
	}

	/**
	 * Get keywords for use on UserSubmissionHolder_Listing template.
	 *
	 * @var string
	 */
	public function getKeywords() {
		if ($this->HasSearched && ($field = $this->fields->dataFieldByName('Keywords')))
		{
			return isset($_REQUEST['Keywords']) ? (string)$_REQUEST['Keywords'] : '';
		}
		return '';
	}

	public function getFormFieldFromEditableFormField(EditableFormField $fieldRecord)
	{
		$field = $fieldRecord->getFormField();
		// Remove templates added by EditableFormField
		$field->setFieldHolderTemplate(null);
		$field->setTemplate(null);
		// Simplify dropdown to only have options that are used
		if ($field->hasMethod('getSource')) {
			$source = $field->getSource();
			
			$availableSources = SubmittedFormField::get()->filter(array(
				'ParentID' => $this->controller->data()->PublishedSubmittedFormIDs(),
				'Name' => $fieldRecord->Name,
				'Value' => array_keys($source),
			))->column('Value');

			if (!$availableSources)
			{
				// Don't show the field if there is nothing to search on.
				return null;
			}

			$newSources = array();
			foreach ($availableSources as $value)
			{
				if (isset($source[$value]))
				{
					$newSources[$value] = $source[$value];
				}
			}

			if (!$newSources)
			{
				// Don't show the field if there is nothing to search on.
				return null;
			}

			$field->setSource($newSources);
		}
		if ($field->hasMethod('setHasEmptyDefault') && ($dropdownEmptyString = $this->config()->dropdown_empty_string))
		{
			// Defaults to '- Please select -', configured above.
			$field->setEmptyString($dropdownEmptyString);
		}
		// Attach EditableFormField to differentiate EditableFormField fields from regular ones
		// in the form.
		$field->EditableFormField = $fieldRecord;
		return $field;
	}

	public function doSearch($data) {
		$userSubmissionHolder = $this->controller->data();
		$userSubmissionHolder->SearchData = new ArrayData(array('Keywords' => 'asfaf'));

		// Get list of page IDs of approved submissions
		$submissionIDs = $userSubmissionHolder->PublishedSubmittedFormIDs();

		// If the 'Keywords' field exists, then utilize sent Keywords data.
		$keywords = '';
		if (isset($data['Keywords']) && $data['Keywords'] && ($this->Fields()->dataFieldByName('Keywords')))
		{
			$keywords = $data['Keywords'];
		}
		
		// Sets up where statements to be seperated by disjunctive (OR) or conjunctive (AND) keywords.
		$wheres = array();
		foreach ($this->Fields() as $field)
		{
			if ($field->EditableFormField)
			{
				$name = $field->getName();
				if (isset($data[$name]) && ($value = $data[$name]))
				{
					$nameEscaped = Convert::raw2sql($name);
					if ($field instanceof DropdownField) {
						// eg. (Name = 'EditableTextField_34' AND Value = 'VIC')
						$valueEscaped = (is_string($value)) ? "'".Convert::raw2sql($value)."'" : (int)$value;
						$wheres[$name] = array(
							"SubmittedFormField.Name = '{$nameEscaped}'",
							"SubmittedFormField.Value = $valueEscaped",
						);
					} else {
						// eg. (Name = 'EditableTextField_33' AND Value LIKE '%hello%')
						$valueEscaped = (is_string($value)) ? "LIKE '%".Convert::raw2sql($value)."%'" : '= '.((int)$value);
						$wheres[$name] = array(
							"SubmittedFormField.Name = '{$nameEscaped}'",
							"SubmittedFormField.Value $valueEscaped",
						);
					}
				}
			}
		}

		// Do a keyword search on each of the fields that have it enabled.
		//
		// eg: (((Name = 'EditableTextField_33' AND Value LIKE '%hello%') OR (Name = 'EditableTextField_42' AND Value LIKE '%hello%')))
		//
		if ($keywords)
		{
			$whereKeywords = array();
			$keywordsEscaped = Convert::raw2sql($keywords);
			foreach ($userSubmissionHolder->Fields() as $editableFormField)
			{
				if ($editableFormField->UseInKeywordSearch)
				{
					$nameEscaped = Convert::raw2sql($editableFormField->Name);
					$whereKeywords[$editableFormField->Name.'_keywords'] = array(
						"SubmittedFormField.Name = '{$nameEscaped}'",
						"SubmittedFormField.Value LIKE '%{$keywordsEscaped}%'",
					);
				}
			}
			if ($whereKeywords)
			{
				$whereKeywordsSQL = '';
				foreach ($whereKeywords as $whereGroup)
				{
					$whereKeywordsSQL .= ($whereKeywordsSQL) ? ' OR ' : '';
					$whereKeywordsSQL .= '('.implode(' AND ', $whereGroup).')';
				}
				$wheres['_keywords'] = array($whereKeywordsSQL);
			}
		}

		// Only search form field values that belong to a SubmittedForm object that belongs to
		// a UserSubmissionPage (or page extended with UserSubmissionExtended)
		$list = SubmittedForm::get()
			->filter('ID', $submissionIDs)
			->innerJoin('SubmittedFormField', 'SubmittedForm.ID = SubmittedFormField.ParentID')
			->alterDataQuery(function($query) {
				// This is so you can match against multiple submitted form fields, and do something like "having 3 matches", where 3 is the number of user filters.
				$query->groupby('SubmittedFormField.ParentID');
			});

		// For explicit searches on fields, ie selecting a dropdown value or typing on a text field
		// that searches on a specific field.
		//
		// eg. (Name = 'EditableTextField_34' AND Value = 'VIC') AND (Name = 'EditableTextField_34' AND Value LIKE '%school%')
		//
		if ($wheres)
		{
			$whereSQL = '';
			foreach ($wheres as $whereGroup)
			{
				$whereSQL .= ($whereSQL) ? ' OR ' : '';
				$whereSQL .= '('.implode(' AND ', $whereGroup).')';
			}
			$list = $list
				->where($whereSQL)
				->alterDataQuery(function($query) use($wheres) {
					// This is so you can match against multiple submitted form fields, and do something like "having 3 matches", where 3 is the number of user filters.
					$query->having('COUNT(*) >= ' . count($wheres));
				});
		}

		$resultRecords = array();
		foreach($list as $submission) {
			if (($page = $submission->UserSubmissionPage()))
			{
				$resultRecords[$page->ClassName.'_'.$page->ID] = $page;
			}
		}

		$userSubmissionHolder->AllListing = new ArrayList($resultRecords);
		return array();
	}
}
