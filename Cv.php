<?php

/**
 * <b>class Cv</b>
 *
 * <p>Model representing resumes
 * received from partners' sites
 * </p>
 *
 * @uses BaseItemModel
 * @since <1.0.0>
 * @version <1.0.0>
 * @package models
 *
 * @property string $id
 * @property integer $site_id
 * @property string $external_id
 * @property string $title
 * @property string $name
 * @property string $description
 * @property string $created_at
 * @property string $updated_at
 * @property string $expires_at
 * @property string $url
 * @property string $ext_category_names
 * @property string $ext_region_names
 * @property string $hash
 */
class Cv extends BaseItemModel
{
    const TABLE_NAME = 'cvs';

    /**
     * Returns the static model of the specified AR class.
     * @param string $className
     * @return Cv the static model class
     */
    public static function model($className=__CLASS__)
    {
        return parent::model($className);
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        return array(
            array('title, url, site_id, description, created_at', 'required'),
            array('site_id, external_id', 'numerical', 'integerOnly'=>true),
            array('url', 'url', 'pattern' => '/^(?:(?#protocol){schemes}:\/\/)?(?:(?#domain)[-A-Z0-9.]+)(?:(?#file)\/[-A-Z0-9+&@#\/%=~_|!:,.;]*)?(?:(?#parameters)\\?[-A-Z0-9+&@#\/%=~_|!:,.;]*)?$/i'),
            array('expires_at,updated_at,created_at', 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'),
//            array('created_at,updated_at', 'date', 'format' => 'yyyy-MM-dd'),
            array('title, name, url', 'length', 'max'=>255),
            array('hash', 'length', 'max'=>40),
            array('description, title, external_id, url, updated_at, expires_at, ext_category_names, ext_region_names', 'safe'),

            array('id, site_id, external_id, title, name, description, created_at, updated_at, expires_at, url, ext_category_names, ext_region_names, hash', 'safe', 'on'=>'search'),
        );
    }

    /**
     * @return array relational rules.
     */
    public function relations()
    {
        return [
            'site' => [self::BELONGS_TO, 'SiteModel', 'site_id']
        ];
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels()
    {
        return [
           /* 'id'            => 'ID',
            'site_id'       => 'Site',
            'external_id'   => 'External',
            'title'         => 'Title',
            'name'          => 'Name',
            'description'   => 'Description',
            'created_at'    => 'Created At',
            'updated_at'    => 'Updated At',
            'expires_at'    => 'Expires At',
            'url'           => 'Url',
            'ext_category_names'    => 'Ext Category Names',
            'ext_region_names'      => 'Ext Region Names',
            'hash'          => 'Hash',*/
        ];
    }

    /**
     * Retrieves a list of models based on the current search/filter conditions.
     * @return CActiveDataProvider the data provider that can return the models based on the search/filter conditions.
     */
    public function search()
    {
        $criteria = new CDbCriteria;

        $criteria->compare('id',$this->id,true);
        $criteria->compare('site_id',$this->site_id);
        $criteria->compare('external_id',$this->external_id,true);
        $criteria->compare('title',$this->title,true);
        $criteria->compare('name',$this->name,true);
        $criteria->compare('description',$this->description,true);
        $criteria->compare('created_at',$this->created_at,true);
        $criteria->compare('updated_at',$this->updated_at,true);
        $criteria->compare('expires_at',$this->expires_at,true);
        $criteria->compare('url',$this->url,true);
        $criteria->compare('ext_category_names',$this->ext_category_names,true);
        $criteria->compare('ext_region_names',$this->ext_region_names,true);
        $criteria->compare('hash',$this->hash,true);

        return new CActiveDataProvider($this, [
            'criteria' => $criteria
        ]);
    }

    public static function calcItemHash($data)
    {
        $salt = '';

        if (isset($data['import_config_id'])) {
            $exist = Y::dbc(CConf::DEF)
                            ->select('count(*)')
                            ->from(SiteImportConfigModel::getTableName())
                            ->where('id = :id AND is_context=1', [':id' => $data['import_config_id']])
                            ->queryScalar();

            if ($exist) {
                $salt = __METHOD__ . __CLASS__;
            }
        }

        return md5($data['title'] . $data['name'] . strip_tags($data['description']) . $salt);
    }
    
    public function getErrorMessage($attribute=null){
        $errors = parent::getErrors($attribute);
        foreach ($errors as $name=>$value){
            return "$name: {$value[0]}";
        }
        return FALSE;
    }
    
    public static function getExpiresAt(){
        return date('Y-m-d H:m:s', strtotime('+15 days'));
    }
    
    public static function findByUserCvModel(UserCvModel $userCvModel){
        $countryId = Y::holder()->getDefaultRegionForHolder($userCvModel->holder_id);
        $pSite = Y::holder()->getSiteForRegion($countryId);
        $site = SiteModel::model()->findByAttributes(['name' => $pSite->name]);
        if(!$site){
            return NULL;
        }
        
        return self::model()->findByAttributes([
            'external_id'=>$userCvModel->id, 'country_id'=>$countryId, 'site_id'=>$site->id
        ]);
    }

    /**
     * Get a row with the current ID from the index
     * @param int $id
     * @param string $indexName

     * @return array|bool
     */
    public static function getFromSphinx($id, $indexName)
    {
        $query = StringHelper::processVars('SELECT * FROM :index WHERE id=:id',[
            ':index' => $indexName,
            ':id'    => (int) $id
        ]);

        return Yii::app()->di->make('$sphinx')->createConnection($indexName)->createCommand($query)->queryRow();
    }
    
    private static function getAllIdFromIndex($indexName)
    {
        $id = 0;
        $idx = [];
        do {
            
            $_idx = Yii::app()->di->make('$sphinx')->createConnection($indexName)->createCommand("SELECT id FROM $indexName WHERE id > $id LIMIT 1000")->queryColumn();
            if(empty($_idx)){
                break;
            }
            
            $idx = array_merge($idx, $_idx);
            $id = end($_idx);
            
        } while (TRUE);
        
        return $idx;
    }

    /**
     * Adds a row with the current ID in the index
     * @param array $cvData
     * @param string $indexName
     * @return boolean
     */
    public static function addToSphinxIndex(array $cvData, $indexName)
    {
        $indexFields = self::getSphinxIndexFields($cvData);
        if (!$indexFields) {
            return false;
        }
        
        $index = self::getFromSphinx($indexFields['id'], $indexName);
        if ($index) {
            self::deleteFromSphinxIndex($indexFields['id'], $indexName);
        }
        
        $res = Yii::app()->di->make('$sphinx')->createConnection($indexName)->createCommand()->insert($indexName, $indexFields);
        usleep(50);
        return $res;
    }

    /**
     * Delete a row with the current ID from the sphinx index
     * @param int $id
     * @param string $indexName
     * @return bool
     */
    public static function deleteFromSphinxIndex($id, $indexName)
    {
        $query = StringHelper::processVars('DELETE FROM :index WHERE id=:id', [
            ':index' => $indexName,
            ':id'    => $id
        ]);

        return Yii::app()->di->make('$sphinx')->createConnection($indexName)->createCommand($query)->execute();
    }
    
    private static function getSphinxIndexFields(array $cvData)
    {
        $indexFields = [];
        
        $indexFields['id'] = $cvData['id'];
        if (!array_key_exists('site_id', $cvData)) {
            return FALSE;
        }
        $indexFields['site_id'] = $cvData['site_id'];
        if (!array_key_exists('external_id', $cvData)) {
            return FALSE;
        }
        $indexFields['external_id'] = $cvData['external_id'];
        if (!array_key_exists('created_at', $cvData)) {
            return FALSE;
        }
        else {
            $time = strtotime($cvData['created_at']);
            $indexFields['created_at'] = $time ? $time : $cvData['created_at'];
        }
        if (!array_key_exists('updated_at', $cvData)) {
            return FALSE;
        }
        else {
            $time = strtotime($cvData['updated_at']);
            $indexFields['updated_at'] = $time ? $time : $cvData['updated_at'];
        }
        if (!empty($cvData['category_names'])) {
            $indexFields['category_names'] = SearchModel::escapeQuotesQL($cvData['category_names']);
        }
        elseif (!empty($cvData['ext_category_names'])) {
            $indexFields['category_names'] = SearchModel::escapeQuotesQL($cvData['ext_category_names']);
        }
        else {
            $indexFields['category_names'] = '';
        }
        if (!empty($cvData['region_names'])) {
            $indexFields['region_names'] = SearchModel::escapeQuotesQL($cvData['region_names']);
        }
        elseif (!empty($cvData['ext_region_names'])) {
            $indexFields['region_names'] = SearchModel::escapeQuotesQL($cvData['ext_region_names']);
        }
        else {
            $indexFields['region_names'] = '';
        }
        
        $indexFields['title'] = array_key_exists('title', $cvData) ? SearchModel::escapeQuotesQL($cvData['title']) : '';
        $indexFields['name'] = array_key_exists('name', $cvData) ? SearchModel::escapeQuotesQL($cvData['name']) : '';
        $indexFields['country_id'] = array_key_exists('country_id', $cvData) ? $cvData['country_id'] : '';
        $indexFields['description'] = array_key_exists('description', $cvData) ? SearchModel::escapeQuotesQL($cvData['description']) : '';
        $indexFields['full_text'] = array_key_exists('full_text', $cvData) ? SearchModel::escapeQuotesQL($cvData['full_text']) : '';
        
        foreach ($indexFields as $key => &$item) {
            if (in_array($key, ['title', 'category_names', 'region_names', 'name', 'description', 'full_text']) && is_null($item)){
                $item = '';
            } elseif (in_array($key, ['site_id', 'external_id']) && empty($item)) {
                $item = 0;
            }
        }
        
        return $indexFields;
    }

    public function getShowType()
    {
        return ShowType::CV;
    }
}
