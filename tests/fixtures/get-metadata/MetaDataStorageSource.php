<?php
declare(strict_types=1);

namespace Pitfalls\GetMetadata;

abstract class MetaDataStorageSource
{
    /**
     * @param string[] $ids
     * @param string $set
     */
    public function getMetaDataForEntities(array $ids, string $set): array
    {
        return $this->getMetaDataForEntitiesIndividually($ids, $set);
    }

    /**
     * @param string[] $ids
     * @param string $set
     */
    protected function getMetaDataForEntitiesIndividually(array $ids, string $set): array
    {
        $out = [];
        foreach ($ids as $id) {
            $data = $this->getMetaData($id, $set);
            if ($data !== null) {
                $out[$id] = $data;
            }
        }
        return $out;
    }

    protected function getMetaData(string $id, string $set)
    {
        throw new \RuntimeException();
    }
}
