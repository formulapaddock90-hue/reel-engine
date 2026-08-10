import { renderMediaOnLambda, getRenderProgress } from '@remotion/lambda/client';

export interface RenderParams {
  projectId: string;
  compositionId: string;
  videoUrl: string;
  voiceUrl: string;
  subtitles: Array<{ word: string; start: number; end: number }>;
}

export const renderReelOnAWSLambda = async (params: RenderParams) => {
  const { renderId, bucketName } = await renderMediaOnLambda({
    region: 'us-east-1',
    functionName: 'remotion-render-3-3-92',
    composition: params.compositionId,
    framesPerLambda: 30, // Split 9:16 rendering across multiple parallel serverless nodes
    inputProps: {
      projectId: params.projectId,
      videoUrl: params.videoUrl,
      voiceUrl: params.voiceUrl,
      subtitles: params.subtitles,
    },
    codec: 'h264',
    imageFormat: 'jpeg',
    maxRetries: 3,
  });

  console.log(`[Remotion Lambda] Render job dispatched with ID: ${renderId}`);
  return {
    renderId,
    bucketName,
    downloadUrl: `https://${bucketName}.s3.amazonaws.com/renders/${renderId}.mp4`,
  };
};

export const checkRenderStatus = async (renderId: string, bucketName: string) => {
  const progress = await getRenderProgress({
    region: 'us-east-1',
    functionName: 'remotion-render-3-3-92',
    renderId,
    bucketName,
  });

  return {
    percent: Math.round(progress.overallProgress * 100),
    done: progress.done,
    outputUrl: progress.outputFile,
  };
};
