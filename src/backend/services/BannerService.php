<?php
/**
 * @link https://github.com/yiimaker/yii2-banner
 * @copyright Copyright (c) 2017 Yii Maker
 * @license BSD 3-Clause License
 */

namespace ymaker\banner\backend\services;

use Yii;
use yii\base\Object;
use yii\data\ActiveDataProvider;
use yii\db\Connection;
use yii\di\Instance;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;
use ymaker\banner\backend\exceptions\FileUploadException;
use ymaker\banner\backend\models\entities\Banner;
use ymaker\banner\backend\models\entities\BannerTranslation;
use ymaker\banner\common\components\FileManagerInterface;

/**
 * Service for banner.
 *
 * @author Vladimir Kuprienko <vldmr.kuprienko@gmail.com>
 * @since 1.0
 */
class BannerService extends Object implements BannerServiceInterface
{
    /**
     * @var string|array|Connection
     */
    private $_db = 'db';
    /**
     * @var FileManagerInterface
     */
    private $_fileManager;
    /**
     * @var Banner
     */
    private $_model;


    /**
     * @inheritdoc
     * @param FileManagerInterface $fileManager
     */
    public function __construct(FileManagerInterface $fileManager, $config = [])
    {
        $this->_fileManager = $fileManager;
        parent::__construct($config);
    }

    /**
     * @param string|array|Connection $db
     */
    public function setDb($db)
    {
        $this->_db = $db;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->_db = Instance::ensure($this->_db, Connection::class);
    }

    /**
     * @return \yii\data\ActiveDataProvider
     */
    public function getDataProvider()
    {
        return new ActiveDataProvider([
            'db' => $this->_db,
            'query' => Banner::find()->with('translations'),
        ]);
    }

    /**
     * @param int $id
     * @return Banner
     * @throws NotFoundHttpException
     */
    private function findModel($id)
    {
        if ($model = Banner::findOne($id)) {
            return $model;
        }
        throw new NotFoundHttpException();
    }

    /**
     * Returns primary model object.
     *
     * @param null|int $id
     * @return Banner
     * @throws NotFoundHttpException
     */
    public function getModel($id = null)
    {
        if ($id === null) {
            $model = new Banner();
            $model->loadDefaultValues();
            $this->_model = $model;
        } else {
            $this->_model = $this->findModel($id);
        }

        return $this->_model;
    }

    /**
     * Save uploaded file to file system.
     *
     * @param BannerTranslation $model
     * @return string
     * @throws FileUploadException
     */
    private function saveUploadedFile($model)
    {
        $uploadedFile = UploadedFile::getInstance($model, 'imageFile');
        if ($uploadedFile === null) {
            return $model->file_name;
        }

        $fileName = $this->_fileManager->generateFileName($uploadedFile->extension);
        if ($uploadedFile->saveAs($this->_fileManager->getImageSrc($fileName))) {
            if (!$model->getIsNewRecord()) {
                $this->_fileManager->deleteFile($model->file_name);
            }
            return $fileName;
        }

        throw new FileUploadException('Error code #' . $uploadedFile->error);
    }

    /**
     * Save banner to database.
     *
     * @param array $data
     * @throws FileUploadException
     * @throws \DomainException
     * @throws \RuntimeException
     */
    protected function saveInternal(array $data)
    {
        if (!$this->_model->load($data)) {
            throw new \DomainException('Cannot load data to primary model');
        }
        foreach ($data[BannerTranslation::internalFormName()] as $language => $dataSet) {
            $model = $this->_model->getTranslation($language);
            $model->file_name = $this->saveUploadedFile($model);
            foreach ($dataSet as $attribute => $translation) {
                $model->$attribute = $translation;
            }
        }

        if (!$this->_model->save()) {
            throw new \RuntimeException();
        }
    }

    /**
     * Save banner and log exceptions.
     *
     * @param array $data
     * @return bool
     */
    public function save(array $data)
    {
        try {
            $this->saveInternal($data);
            return true;
        } catch (\Exception $ex) {
            Yii::$app->getErrorHandler()->logException($ex);
        }

        return false;
    }

    /**
     * Removes banner.
     *
     * @param int $id
     * @return bool
     * @throws NotFoundHttpException
     */
    public function delete($id)
    {
        $model = $this->findModel($id);
        try {
            foreach ($model->translations as $translation) {
                if (!$this->_fileManager->deleteFile($translation->file_name)) {
                    Yii::trace('Cannot delete "' . $translation->file_name . '" file', 'yii2-banner');
                }
            }
            return (bool)$model->delete();
        } catch (\Exception $ex) {
            Yii::$app->getErrorHandler()->logException($ex);
        }

        return false;
    }
}
