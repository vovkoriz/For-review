<?php

/**
 * <b>class Job</b>
 *
 * <p>Model representing jobs (vacancies)
 * received from partners' sites
 * </p>
 *
 * @uses BaseItemModel
 * @since <1.0.0>
 * @version <1.0.0>
 * @package models
 *
 * @property integer $id
 * @property string $title
 * @property string $description
 * @property string $site_id
 * @property string $external_id
 * @property string $employer
 * @property string $ext_category_names
 * @property string $ext_region_names
 * @property string $created_at
 * @property string $updated_at
 * @property string $expires_at
 * @property string $url
 * @property string $hash
 * @property string $salary
 *
 * @property Site $site
 * @property Site[] $sites
 */
class Job extends BaseItemModel 
{
    const TABLE_NAME = 'jobs';

    const IS_JOB_A_FREELANCE_CACHE_PREFIX = 'isJobAFreelance_';

    private $_userJob = false;
    protected $extCategoriesList;

    protected $_readOnly = false;

    /**
     * Returns the static model of the specified AR class.
     * @param string $className
     * @return Job the static model class
     */
    public static function model($className=__CLASS__)
    {
        return parent::model($className);
    }

    public function getDbConnection()
    {
        return $this->_readOnly ? Yii::app()->dbSlave : Yii::app()->db;
    }
    
    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        return [
            ['title, url, site_id, description', 'required'],
            ['external_id', 'numerical', 'integerOnly' => true, 'allowEmpty' => false, 'on' => 'api'],
            ['url', 'url', 'pattern' => '/(*UTF8)^(?:(?#protocol){schemes}:\/\/)?(?:(?#domain)[-A-Z0-9.]+)(?:(?#file)\/[-A-Z0-9+&@#\/%=~_|!:,.;]*)?(?:(?#parameters)\?[-\p{L}A-Z0-9+&@#\/%=~_|!:,.;]*)?$/i'],
            ['title', 'length', 'max' => 200],
            ['employer', 'length', 'max' => 100],
            ['created_at,expires_at,updated_at', 'date', 'format' => 'yyyy-MM-dd'],
            ['external_id,site_id', 'numerical', 'integerOnly' => true],
            ['salary', 'length', 'max'=>255],
            ['hash', 'length', 'max' => 40],
            ['description, ext_category_names, ext_region_names, expires_at, title, url, external_id, salary', 'safe'],

            ['id, title, description, site_id, external_id, employer, ext_category_names, ext_region_names, created_at, updated_at, expires_at, url, hash, salary', 'safe', 'on'=>'search'],
        ];
    }

    /**
     * @return array relational rules.
     */
    public function relations()
    {
        return [
            'site' => [self::BELONGS_TO, 'SiteModel', 'site_id'],
            'company' => [self::BELONGS_TO, 'CompanyModel', 'employer_v'],
            'importConfig' => [self::BELONGS_TO, 'SiteImportConfigModel', 'import_config_id'],
            'isAbroad' => [self::BELONGS_TO, 'AbroadJobModel', 'id'],
            'isParttime' => [self::BELONGS_TO, 'ParttimeJobModel', 'id'],
        ];
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels()
    {
        return [];
    }

    /**
     * Retrieves a list of models based on the current search/filter conditions.
     * @return CActiveDataProvider the data provider that can return the models based on the search/filter conditions.
     */
    public function search()
    {
        $criteria = new CDbCriteria;

        $criteria->compare('id',$this->id);
        $criteria->compare('title',$this->title,true);
        $criteria->compare('description',$this->description,true);
        $criteria->compare('site_id',$this->site_id,true);
        $criteria->compare('external_id',$this->external_id,true);
        $criteria->compare('employer',$this->employer,true);
        $criteria->compare('ext_category_names',$this->ext_category_names,true);
        $criteria->compare('ext_region_names',$this->ext_region_names,true);
        $criteria->compare('created_at',$this->created_at,true);
        $criteria->compare('updated_at',$this->updated_at,true);
        $criteria->compare('expires_at',$this->expires_at,true);
        $criteria->compare('url',$this->url,true);
        $criteria->compare('hash',$this->hash,true);
        $criteria->compare('salary',$this->salary,true);

        return new CActiveDataProvider($this, [
            'criteria' => $criteria
        ]);
    }

    /**
     * Finds job linked to site with siteId
     * @param int $siteId
     * @return Job
     */
    public function findJobForSite($siteId)
    {
        return Job::model()->findByAttributes([
            'hash'      => $this->hash,
            'site_id'   => $siteId,
        ]);
    }

    /**
     * Finds job by it's hash
     * @param string $hash
     * @return Job
     */
    public static function findJobByHash($hash)
    {
        return Job::model()->findByAttributes([
            'hash' => $hash,
        ]);
    }

    public static function calcItemHash($data, $forceImproved = false)
    {
        $salt = '';
        $improvedHashing = $forceImproved;
        $regions = $data['ext_region_names'];

        if (isset($data['import_config_id'])) {
            $exist = Y::dbc(CConf::DEF)
                            ->select('count(*)')
                            ->from(SiteImportConfigModel::getTableName())
                            ->where('id = :id AND is_context=1', [':id' => $data['import_config_id']])
                            ->queryScalar();

            if ($exist) {
                $salt = __METHOD__ . __CLASS__;
            }

            $countryId = Yii::app()->db->createCommand("
                SELECT country
                FROM sites s
                    JOIN site_import_config sic
                        ON sic.site_id = s.id
                WHERE sic.id = :config_id
            ")->bindValues([
                ':config_id' => $data['import_config_id'],
            ])->queryScalar();
            if ($countryId) {
                $country = RegionModel::getRegion($countryId);
                if ($country) {
                    $holderId = Y::holder()->getHolderForCountry($countryId);

                    if ($holderId) {

                        $holder = Y::holder()->createHolder($holderId);

                        if ($holder->improvedJobHashing) {
                            $improvedHashing = true;

                            if ($holder->useRegionIdsForJobHashing) {
                                if ($data['ext_region_names']) {
                                    $importWorker = new ImportBindingWorker();
                                    $regions = $importWorker->getRegionIdsByNamesAndRoot(
                                        $data['ext_region_names'],
                                        $country['root']
                                    );

                                    if ($regions) {
                                        $regions = implode(',', $regions);

                                    } else {
                                        $regions = $data['ext_region_names'];

                                    }
                                }

                            } else {
                                $regions = $data['ext_region_names'];

                            }
                        }
                    }
                }
            }
        }

        if (!$regions)
            $regions = $data['ext_region_names'];

        if ($improvedHashing) {
            return md5(
                StringHelper::getTextHash($data['title'])
                .
                StringHelper::getTextHash($data['description'])
                .
                $regions
                .
                $salt
            );
        } else {
            return md5(
                $data['title']
                .
                strip_tags($data['description'])
                .
                $regions
                .
                $salt
            );
        }
    }

    /**
     * @return string
     */
    public function getRootExtCategoryNames()
    {
        $result = '';

        if (isset($this->getExCategoriesList()[0])) {
            $result = $this->getExCategoriesList()[0];
        }

        return $result;
    }

    /**
     * @return string
     */
    public function getLastExtCategoryNames()
    {
        $cats = $this->getExCategoriesList();
        $result = '';

        if (isset($cats[count($cats) -1])) {
            $result = $cats[count($cats) -1];
        }

        return $result;
    }

    /**
     * Parse ext_category_names to array
     * @return array
     */
    public function getExCategoriesList()
    {
        if (!$this->extCategoriesList) {
            $categories = [];
            foreach (explode(';', $this->ext_category_names) as $item) {
                $categories = array_merge($categories, explode(',', $item));
            }

            $this->extCategoriesList = array_unique(array_map('trim', $categories));
        }

        return $this->extCategoriesList;
    }

    /**
     * Check if record related with user_job
     * @return bool is job related to user_job
     */
    public function isUserJob()
    {
        if(is_null($this->external_id)){
            return false;
        }
        
        return !is_null($this->getUserJob());
    }

    /**
     * Get own user_job
     * @return UserJobModel | null
     */
    public function getUserJob()
    {
        if ($this->_userJob === false) {
            $this->_userJob = UserJobModel::model()->findByPk($this->external_id);
        }
        return $this->_userJob;
    }

    protected function afterFind()
    {
        $parts = explode(',', $this->ext_region_names);
        $parts = array_filter($parts, function($r){return $r != RegionModel::ABROAD_REGION_NAME;});
        $parts = array_map('trim', $parts);
        $parts = array_unique($parts);
        $this->ext_region_names = implode(', ', $parts);
        parent::afterFind();

        if ($this->isUserJob()) {
            $this->url = Yii::app()->createAbsoluteUrl('search/item', [
                'item_id' => $this->id,
                'site_id' => $this->site_id,
                'show' => ShowType::JOB,
                'region' => Y::us()->getRegion(true)->alias
            ]);
        }
    }

    public function getShowType()
    {
        return ShowType::JOB;
    }

    public function readOnly()
    {
        $this->_readOnly = true;
        return $this;
    }

    public function getSearchItems($searchIds)
    {
        $this->readOnly();

        $result = $this->cache(CConf::DEF)->findAllByPk(
            $searchIds, ['order' => 'FIELD(t.id, ' . implode(',', $searchIds) . ')']
        );

        if (!Y::holder()->duplicateSubstitutionEnabled) {
            return $result;
        }

        $itemsByHash = [];
        foreach ($result as $item) {
            $itemsByHash[$item->hash] = $item;
        }

        $duplicates = $this->getDbConnection()->createCommand()
            ->select('hash, site_id, url')
            ->from('duplicate_jobs')
            ->where(['in', 'hash', array_keys($itemsByHash)])
            ->andWhere('expires_at >= CURDATE()')
            ->queryAll();

        $siteIds = [];
        $itemDuplicates = [];
        foreach ($duplicates as $duplicate) {
            if (!empty($itemsByHash[$duplicate['hash']])) {
                $siteIds[] = $duplicate['site_id'];
                $itemDuplicates[$duplicate['hash']][] = $duplicate;
                $siteIds[] = $itemsByHash[$duplicate['hash']]->site_id;
                $itemDuplicates[$duplicate['hash']][] = [
                    'hash' => $duplicate['hash'],
                    'site_id' => $itemsByHash[$duplicate['hash']]->site_id,
                    'url' => $itemsByHash[$duplicate['hash']]->url,
                ];
            }
        }
        $siteIds = array_unique($siteIds);

        $sites = [];

        foreach (SiteModel::model()->findAllByPk($siteIds) as $siteModel) {
            $sites[$siteModel->id] = $siteModel;
        }

        foreach ($itemDuplicates as $hash => $itemDuplicate) {
            $freeDuplicates = [];
            $paidDuplicates = [];
            foreach ($itemDuplicate as $row) {
                if (!empty($sites[$row['site_id']])) {
                    $site = $sites[$row['site_id']];
                    if ($site->isFree() ||
                        $site->getRealCpc() < Y::holder()->site->minimalCpcForPaidDuplicateSubstitution) {
                        $freeDuplicates[] = $row;
                    } else {
                        $paidDuplicates[] = $row;
                    }
                }
            }

            if (count($paidDuplicates) == 1) {
                $duplicate = array_shift($paidDuplicates);
            } elseif (count($paidDuplicates) > 1) {
                $budgetSum = 0;
                $budgetSites = [];
                foreach ($paidDuplicates as $k => $site) {
                    $budgetSum += $sites[$site['site_id']]->budget;
                    $budgetSites[$k] = $budgetSum;
                }
                foreach ($budgetSites as $k => $budgetSite) {
                    if ($budgetSum) {
                        $budgetSites[$k] = $budgetSite / $budgetSum;
                    } else {
                        $budgetSites[$k] = ($k+1) / count($budgetSites);
                    }
                }
                $randPick = mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax();
                $duplicate = null;
                foreach ($budgetSites as $k => $budgetSitePortion) {
                    if ($randPick <= $budgetSitePortion) {
                        $duplicate = $paidDuplicates[$k];
                        break;
                    }
                }
            } elseif ($freeDuplicates) {
                $duplicate = $freeDuplicates[rand(0, count($freeDuplicates) - 1)];
            }
            if (!empty($duplicate)) {
                $itemsByHash[$hash]->site_id = $duplicate['site_id'];
                $itemsByHash[$hash]->url = $duplicate['url'];
            }
        }

        return $result;
    }

    public function substituteWithDuplicate($siteId)
    {
        if (!Y::holder()->duplicateSubstitutionEnabled) {
            return $this;
        }
        if (!$siteId) return $this;
        $duplicate = $this->getDbConnection()
            ->createCommand()
            ->select('site_id, url')
            ->from('duplicate_jobs')
            ->where('hash = :hash AND site_id = :site_id', [
                ':hash' => $this->hash,
                ':site_id' => $siteId,
            ])->queryRow();
        if ($duplicate) {
            $this->site_id = $duplicate['site_id'];
            $this->url = $duplicate['url'];
        }
        return $this;
    }

    /**
     * Check if vacancy is freelance
     * @return bool
     */
    public function isFreelance()
    {
        if (!$this->employment) return false;

        $holderId = Y::holder()->getHolderForCountry($this->country_id);
        $cacheKey = self::IS_JOB_A_FREELANCE_CACHE_PREFIX . (int) $holderId . '_' . (int) $this->id;
        if (($result = Yii::app()->cache->get($cacheKey)) !== false) {
            return (bool) $result;
        }

        $freelanceFilter = FilterModel::getFreelanceFilterId($holderId);
        if (!$freelanceFilter) return false;

        $result = ($this->employment == $freelanceFilter);
        Yii::app()->cache->set($cacheKey, (int) $result, CConf::LONG);

        return $result;
    }

    /**
     * Is vacancy belongs to abroad
     *
     * @param $id
     * @return mixed
     */
    public static function isAbroadById($id)
    {
        return Y::dbc(CacheConf::DEF)
            ->select()
            ->from(AbroadJobModel::getTableName())
            ->where('id = :id', [':id' => $id])
            ->queryScalar();
    }

    /**
     * Is vacancy belongs to parttime
     *
     * @param $id
     * @return mixed
     */
    public static function isParttimeById($id)
    {
        return Y::dbc(CacheConf::DEF)
            ->select()
            ->from(ParttimeJobModel::getTableName())
            ->where('id = :id', [':id' => $id])
            ->queryScalar();
    }
}
