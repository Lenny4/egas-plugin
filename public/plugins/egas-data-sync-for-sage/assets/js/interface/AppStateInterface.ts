import { SyncWebsiteStateEnum } from "../enum/SyncWebsiteStateEnum";
import { TaskJobTypeEnum } from "../enum/TaskJobTypeEnum";

export interface AppStateInterface {
  SyncWebsiteJob: SyncWebsiteJobInterface;
  SubscriptionDto: SubscriptionDto | null;
}

export interface SubscriptionDto {
  id?: string;
  status?: string;
  expiration_date?: string | Date | null;
  meta: SubscriptionMetaDto;
}

export interface SubscriptionMetaDto {
  host?: string;
  port_http?: number | null;
  port_https?: number | null;
  api_key?: string;
  key?: string;
}

export interface SyncWebsiteJobInterface {
  WebsiteId: number;
  Show: boolean;
  NbThreads: number;
  State: SyncWebsiteStateEnum;
  TaskJobSyncWebsiteJobs: TaskJobSyncWebsiteJobInterface[] | null;
}

export interface TaskJobJobInterface {
  Description: string;
  TaskJobType: TaskJobTypeEnum;
}

export interface TaskJobSyncWebsiteJobInterface {
  NbTaskDone: number;
  NewNbTasks: number | null;
  TaskJob: TaskJobJobInterface;
  TaskJobDoneSpeed: number | null;
  RemainingTime: number | null;
}
